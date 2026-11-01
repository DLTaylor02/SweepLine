<?php
declare(strict_types=1);
namespace SweepLine;

use PDO;
final class Auth
{
    public function __construct(private PDO $db) {}
    public static function start(): void
    {
        if (session_status()===PHP_SESSION_ACTIVE) { return; }
        ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
        if (session_name()!=='SweepLineSession') { session_name('SweepLineSession'); }
        session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','path'=>'/',
            'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || getenv('APP_COOKIE_SECURE')==='1']);
        session_start(); $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public static function csrf(?string $token): void
    {
        if (!$token || !hash_equals($_SESSION['csrf']??'',$token)) { throw new HttpException(403,'Session expired or invalid request. Reload the page and try again.'); }
    }
    public function user(): ?array
    {
        if (!isset($_SESSION['user_id'])) { return null; }
        if (time()-($_SESSION['last_seen']??0)>3600 || time()-($_SESSION['login_at']??0)>43200) { unset($_SESSION['user_id']); return null; }
        $q=$this->db->prepare('SELECT id,username,email,name,role,active,must_change_password,session_version FROM app_users WHERE id=?');
        $q->execute([$_SESSION['user_id']]); $user=$q->fetch(PDO::FETCH_ASSOC);
        if (!$user || !$user['active'] || (int)$user['session_version']!==($_SESSION['session_version']??0)) { unset($_SESSION['user_id']); return null; }
        $_SESSION['last_seen']=time(); unset($user['session_version']); $user['id']=(int)$user['id']; return $user;
    }
    public function requireUser(bool|string $admin=false): array
    {
        $user=$this->user();
        if (!$user) { throw new HttpException(401,'Please sign in to continue.'); }
        $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
        if ($user['must_change_password'] && !in_array($path,['/api/password/change','/api/password-policy'],true)) {
            throw new HttpException(403,'Password change required.');
        }
        if (($admin===true && $user['role']!=='superadmin') || ($admin==='admin' && !in_array($user['role'],['admin','superadmin'],true))) { throw new HttpException(403,'SuperAdmin access required.'); }
        return $user;
    }
    public function status(): array
    {
        $notice=$_SESSION['notice']??null;unset($_SESSION['notice']);
        return ['user'=>$this->user(),'csrf'=>$_SESSION['csrf'],'admin_ready'=>SuperAdmin::exists($this->db),'password_policy'=>(new PasswordPolicy($this->db))->read(),'notice'=>$notice];
    }
    public static function validatePassword(mixed $password): string
    {
        return PasswordPolicy::format($password);
    }
    public static function credentials(array $input): array
    {
        $username=$input['username']??null;
        if (!is_string($username) || !preg_match('/^[a-z0-9][a-z0-9_.-]{2,63}$/D',strtolower(trim($username)))) {
            throw new \InvalidArgumentException('Username must contain 3 to 64 letters, numbers, dots, underscores or hyphens.');
        }
        $username=strtolower(trim($username)); $email=$input['email']??null;
        if ($email!==null && $email!=='') {
            if (!is_string($email) || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('Enter a valid email address or leave it blank.'); }
            $email=strtolower(trim($email));
        } else { $email=null; }
        $name=$input['name']??$username;
        if (!is_string($name) || trim($name)==='' || strlen($name)>100) { throw new \InvalidArgumentException('Name must contain 1 to 100 bytes.'); }
        return ['username'=>$username,'email'=>$email,'password'=>self::validatePassword($input['password']??null),'name'=>trim($name)];
    }
    private function throttle(): void
    {
        $key=hash('sha256',$_SERVER['REMOTE_ADDR']??'local');
        $q=$this->db->prepare("INSERT INTO auth_attempts(key,attempts) VALUES (?,1)
            ON CONFLICT(key) DO UPDATE SET attempts=CASE WHEN auth_attempts.window_start<now()-interval '15 minutes' THEN 1 ELSE auth_attempts.attempts+1 END,
            window_start=CASE WHEN auth_attempts.window_start<now()-interval '15 minutes' THEN now() ELSE auth_attempts.window_start END RETURNING attempts");
        $q->execute([$key]);
        if ((int)$q->fetchColumn()>15) { throw new HttpException(429,'Too many sign-in attempts. Try again in 15 minutes.'); }
    }
    private function signIn(array $user): array
    {
        session_regenerate_id(true); $_SESSION['user_id']=(int)$user['id']; $_SESSION['session_version']=(int)$user['session_version'];
        $_SESSION['last_seen']=$_SESSION['login_at']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32)); return $this->status();
    }
    public function login(array $input): array
    {
        $this->throttle(); $login=$input['username']??$input['email']??''; $password=$input['password']??'';
        if (!is_string($login) || !is_string($password) || strlen($login)>254 || strlen($password)>72) { throw new HttpException(401,'Invalid username or password.'); }
        // Existing email-based accounts remain able to sign in after the username migration.
        $q=$this->db->prepare('SELECT * FROM app_users WHERE lower(username)=? OR lower(email)=?');
        $key=strtolower(trim($login)); $q->execute([$key,$key]); $user=$q->fetch(PDO::FETCH_ASSOC);
        $hash=$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!password_verify($password,$hash) || !$user || !$user['active']) { throw new HttpException(401,'Invalid username or password.'); }
        if (password_needs_rehash($hash,PASSWORD_DEFAULT)) { $this->db->prepare('UPDATE app_users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$user['id']]); }
        return $this->signIn($user);
    }
    public function logout(): array
    {
        $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); return $this->status();
    }
    public function users(): array
    {
        $this->requireUser('admin'); return $this->db->query('SELECT id,username,email,name,role,active,must_change_password,created_at FROM app_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function create(array $input): array
    {
        $actor=$this->requireUser('admin'); $c=self::credentials($input);
        $role=$input['role']??'member';
        if (!is_string($role) || !in_array($role,['member','admin'],true)) {throw new \InvalidArgumentException('Role must be member or admin.');}
        if ($actor['role']!=='superadmin' && $role!=='member') {throw new HttpException(403,'Only the SuperAdmin can assign Admin roles.');}
        if ($c['username']==='superadmin') { throw new \InvalidArgumentException('The superadmin username is reserved.'); }
        $warning=(new PasswordPolicy($this->db))->validate($c['password'],$role);
        $must=$input['must_change_password']??false;
        if (!is_bool($must)) {throw new \InvalidArgumentException('must_change_password must be a boolean.');}
        $q=$this->db->prepare("INSERT INTO app_users(username,email,name,password_hash,role,must_change_password) VALUES (?,?,?,?,?,?::boolean) ON CONFLICT DO NOTHING RETURNING id");
        $q->execute([$c['username'],$c['email'],$c['name'],password_hash($c['password'],PASSWORD_DEFAULT),$role,$must?'true':'false']); $id=$q->fetchColumn();
        if (!$id) { throw new HttpException(409,'Username or email is already in use.'); }
        return ['id'=>(int)$id,'warning'=>$warning];
    }
    public function update(int $id,array $input): array
    {
        $actor=$this->requireUser('admin');
        if (array_diff(array_keys($input),['active','password','role','must_change_password'])) {throw new \InvalidArgumentException('Supported updates are active, password, role and must_change_password.');}
        $q=$this->db->prepare('SELECT id,role FROM app_users WHERE id=?');$q->execute([$id]);$target=$q->fetch(PDO::FETCH_ASSOC);
        if (!$target) {throw new HttpException(404,'User not found.');}
        if ($actor['role']!=='superadmin' && ($target['role']!=='member' || array_key_exists('role',$input))) {
            throw new HttpException(403,'Only the SuperAdmin can manage privileged accounts or assign roles.');
        }
        $role=$input['role']??null;
        if (array_key_exists('role',$input) && (!is_string($role) || !in_array($role,['member','admin'],true) || $target['role']==='superadmin')) {
            throw new \InvalidArgumentException('Choose User or Admin; the SuperAdmin role is protected.');
        }
        $active=$input['active']??null;
        if (array_key_exists('active',$input)) {
            if (!is_bool($active)) {throw new \InvalidArgumentException('active must be a boolean.');}
            if ($target['role']==='superadmin' && !$active) {throw new \InvalidArgumentException('The SuperAdmin cannot be disabled.');}
        }
        $must=$input['must_change_password']??null;
        if (array_key_exists('must_change_password',$input) && (!is_bool($must) || $target['role']==='superadmin')) {throw new \InvalidArgumentException('Only regular users and Admins can require a password change.');}
        $warning=null;$newHash=null;
        if (array_key_exists('password',$input)) {
            $warning=(new PasswordPolicy($this->db))->validate($input['password'],$target['role']);
            $newHash=password_hash($input['password'],PASSWORD_DEFAULT);
        }
        $invalidate=$newHash!==null || array_key_exists('active',$input) || array_key_exists('role',$input);
        // Recheck target role in the mutation so an overlapping promotion cannot let an
        // Admin reset or disable an account that has just become privileged.
        $q=$this->db->prepare('UPDATE app_users SET active=COALESCE(?::boolean,active),password_hash=COALESCE(?,password_hash),role=COALESCE(?,role),must_change_password=COALESCE(?::boolean,must_change_password),session_version=session_version+? WHERE id=?'.($actor['role']==='superadmin'?'':" AND role='member'"));
        $q->execute([$active===null?null:($active?'true':'false'),$newHash,$role,$must===null?null:($must?'true':'false'),$invalidate?1:0,$id]);
        if (!$q->rowCount()) {throw new HttpException(403,'Account permissions changed; reload the page.');}
        return ['updated'=>true,'warning'=>$warning];
    }
    public function changePassword(array $input): array
    {
        $user=$this->requireUser();
        $this->throttle();
        $current=$input['current_password']??null;$new=$input['new_password']??null;
        $q=$this->db->prepare('SELECT password_hash,session_version FROM app_users WHERE id=?');$q->execute([$user['id']]);$stored=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_string($current) || strlen($current)>72 || str_contains($current,"\0") || !$stored || !password_verify($current,$stored['password_hash'])) {throw new HttpException(422,'Current password is incorrect.');}
        if (!is_string($new) || $new!==($input['confirmation']??null)) {throw new \InvalidArgumentException('New password and confirmation must match.');}
        if (password_verify(PasswordPolicy::format($new),$stored['password_hash'])) {throw new \InvalidArgumentException('Choose a password different from the current password.');}
        $warning=(new PasswordPolicy($this->db))->validate($new,$user['role']);
        $q=$this->db->prepare('UPDATE app_users SET password_hash=?,must_change_password=false,session_version=session_version+1 WHERE id=? AND session_version=? RETURNING session_version');
        $q->execute([password_hash($new,PASSWORD_DEFAULT),$user['id'],$stored['session_version']]);$version=$q->fetchColumn();
        if ($version===false) {throw new HttpException(409,'Account changed. Reload and try again.');}
        session_regenerate_id(true);$_SESSION['session_version']=(int)$version;
        $_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['last_seen']=$_SESSION['login_at']=time();
        $_SESSION['notice']=$warning??'Password changed successfully.';
        return ['changed'=>true,'redirect'=>'/'];
    }

}
