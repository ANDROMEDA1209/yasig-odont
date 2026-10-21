<?php
return static function (): void {
    $sql = file_get_contents(ROOT . '/database/schema.sql');
    foreach (explode(';', $sql) as $statement) if (trim($statement)) App\Models\DB::connection()->exec($statement);
};
