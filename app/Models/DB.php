<?php
declare(strict_types=1);
namespace App\Models;
use PDO;
final class DB {
    private static ?PDO $pdo = null;
    public static function connection(): PDO {
        if (!self::$pdo) {
            $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
            if (env('DB_SSL_CA')) $options[PDO::MYSQL_ATTR_SSL_CA] = env('DB_SSL_CA');
            self::$pdo = new PDO('mysql:host='.env('DB_HOST','127.0.0.1').';port='.env('DB_PORT','3306').';dbname='.env('DB_DATABASE','yasig_odont').';charset=utf8mb4',env('DB_USERNAME','root'),env('DB_PASSWORD'),$options);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }
    public static function query(string $sql, array $params=[]): \PDOStatement { $s=self::connection()->prepare($sql); $s->execute($params); return $s; }
    public static function all(string $sql, array $params=[]): array { return self::query($sql,$params)->fetchAll(); }
    public static function one(string $sql, array $params=[]): ?array { return self::query($sql,$params)->fetch() ?: null; }
    public static function insert(string $table, array $data): int {
        $keys=array_keys($data); self::query('INSERT INTO `'.$table.'` (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($data));
        return (int)self::connection()->lastInsertId();
    }
    public static function transaction(callable $fn): mixed {
        $p=self::connection(); $p->beginTransaction();
        try { $result=$fn(); $p->commit(); return $result; } catch (\Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
    }
    public static function audit(string $action, string $entity, int $id=0, string $detail=''): void {
        self::insert('audit_logs',['user_id'=>$_SESSION['user_id'] ?? null,'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'detail'=>$detail]);
    }
}
