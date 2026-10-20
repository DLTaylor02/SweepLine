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
        $q=$this->db->prepare('SELECT id,username,email,name,role,active,session_version FROM app_users WHERE id=?');
        $q->execute([$_SESSION['user_id']]); $user=$q->fetch(PDO::FETCH_ASSOC);
        if (!$user || !$user['active'] || (int)$user['session_version']!==($_SESSION['session_version']??0)) { unset($_SESSION['user_id']); return null; }
        $_SESSION['last_seen']=time(); unset($user['session_version']); $user['id']=(int)$user['id']; return $user;
    }
    public function requireUser(bool $admin=false): array
    {
        $user=$this->user();
        if (!$user) { throw new HttpException(401,'Please sign in to continue.'); }
        if ($admin && $user['role']!=='superadmin') { throw new HttpException(403,'SuperAdmin access required.'); }
        return $user;
    }
    public function status(): array
    {
        return ['user'=>$this->user(),'csrf'=>$_SESSION['csrf'],'admin_ready'=>SuperAdmin::exists($this->db)];
    }
    public static function validatePassword(mixed $password): string
    {
        if (!is_string($password) || strlen($password)<12 || strlen($password)>72 || str_contains($password,"\0")) {
            throw new \InvalidArgumentException('Passwords must contain 12 to 72 bytes and no null characters.');
        }
        return $password;
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
        $this->requireUser(true); return $this->db->query('SELECT id,username,email,name,role,active,created_at FROM app_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function create(array $input): array
    {
        $this->requireUser(true); $c=self::credentials($input);
        if ($c['username']==='superadmin') { throw new \InvalidArgumentException('The superadmin username is reserved.'); }
        $q=$this->db->prepare("INSERT INTO app_users(username,email,name,password_hash,role) VALUES (?,?,?,?,'member') ON CONFLICT DO NOTHING RETURNING id");
        $q->execute([$c['username'],$c['email'],$c['name'],password_hash($c['password'],PASSWORD_DEFAULT)]); $id=$q->fetchColumn();
        if (!$id) { throw new HttpException(409,'Username or email is already in use.'); }
        return ['id'=>(int)$id];
    }
    public function update(int $id,array $input): array
    {
        $actor=$this->requireUser(true);
        if (array_diff(array_keys($input),['active','password'])) { throw new \InvalidArgumentException('Supported updates are active and password.'); }
        $q=$this->db->prepare('SELECT id FROM app_users WHERE id=?'); $q->execute([$id]);
        if (!$q->fetchColumn()) { throw new HttpException(404,'User not found.'); }
        $newHash=isset($input['password'])?password_hash(self::validatePassword($input['password']),PASSWORD_DEFAULT):null;
        if (array_key_exists('active',$input)) {
            if (!is_bool($input['active'])) { throw new \InvalidArgumentException('active must be a boolean.'); }
            if ($id===$actor['id'] && !$input['active']) { throw new \InvalidArgumentException('The SuperAdmin cannot disable their own account.'); }
            $this->db->prepare('UPDATE app_users SET active=?::boolean,session_version=session_version+1 WHERE id=?')->execute([$input['active']?'true':'false',$id]);
        }
        if ($newHash!==null) { $this->db->prepare('UPDATE app_users SET password_hash=?,session_version=session_version+1 WHERE id=?')->execute([$newHash,$id]); }
        return ['updated'=>true];
    }
}
