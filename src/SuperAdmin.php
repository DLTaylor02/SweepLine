<?php
declare(strict_types=1);
namespace SweepLine;

use PDO;
final class SuperAdmin
{
    public static function exists(PDO $db): bool
    {
        return (bool)$db->query("SELECT EXISTS(SELECT 1 FROM app_users WHERE role='superadmin')")->fetchColumn();
    }
    /** Create once. Never reset credentials on installer reruns or concurrent provisioning. */
    public static function ensure(PDO $db,string $password): bool
    {
        $db->beginTransaction();
        try {
            $db->exec('LOCK TABLE app_users IN EXCLUSIVE MODE');
            if (self::exists($db)) { $db->commit(); return false; }
            $password=Auth::validatePassword($password);
            $q=$db->prepare("INSERT INTO app_users(username,email,name,password_hash,role) VALUES ('superadmin',null,'SuperAdmin',?,'superadmin')");
            $q->execute([password_hash($password,PASSWORD_DEFAULT)]);
            $db->commit(); return true;
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }

    /** Explicit local recovery: preserve the account, reactivate it and invalidate all sessions. */
    public static function reset(PDO $db,string $password): string
    {
        $password=Auth::validatePassword($password);
        $q=$db->prepare("UPDATE app_users SET password_hash=?,active=true,session_version=session_version+1 WHERE role='superadmin' RETURNING username");
        $q->execute([password_hash($password,PASSWORD_DEFAULT)]); $username=$q->fetchColumn();
        if ($username===false) { throw new \RuntimeException('No SuperAdmin exists. Run the installer to create one.'); }
        return $username;
    }
}
