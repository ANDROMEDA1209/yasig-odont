<?php
declare(strict_types=1);
namespace App\Validators;
final class Input {
    public static function text(array $data,string $key,int $max=255,bool $required=true): string {
        $raw=$data[$key] ?? '';
        if (!is_scalar($raw) && $raw!==null) throw new ValidationException($key,'Introduce un valor válido en este campo.');
        $v=trim((string)$raw);
        if ($required && $v==='') throw new ValidationException($key,'Completa este campo obligatorio.');
        if (mb_strlen($v)>$max) throw new ValidationException($key,'Escribe un máximo de '.$max.' caracteres.');
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v)) throw new ValidationException($key,'Elimina los caracteres no permitidos de este campo.');
        return $v;
    }
    public static function id(array $data,string $key): int { $v=filter_var($data[$key] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if (!$v) throw new ValidationException($key,'Selecciona una opción válida.'); return $v; }
    public static function email(array $data,string $key='email',bool $required=true): string { $v=strtolower(self::text($data,$key,190,$required)); if ($v!=='' && !filter_var($v,FILTER_VALIDATE_EMAIL)) throw new ValidationException($key,'Introduce un correo válido, por ejemplo nombre@correo.com.'); return $v; }
    public static function date(string $value,string $field=''): string { $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value); if (!$d || $d->format('Y-m-d')!==$value) throw new ValidationException($field,'Introduce una fecha válida con día, mes y año.'); return $value; }
    public static function choice(array $data,string $key,array $values): string { $v=self::text($data,$key); if (!in_array($v,$values,true)) throw new ValidationException($key,'Selecciona una de las opciones disponibles.'); return $v; }
    public static function cents(array $data,string $key): int { $raw=$data[$key] ?? ''; if (!is_scalar($raw)) throw new ValidationException($key,'Introduce un importe válido con un máximo de dos decimales.'); $v=trim((string)$raw); if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/D',$v)) throw new ValidationException($key,'Introduce un importe válido con un máximo de dos decimales.'); [$whole,$fraction]=array_pad(explode('.',$v,2),2,''); return (int)$whole*100+(int)str_pad($fraction,2,'0'); }
    public static function password(string $value,string $field='password'): string { if (strlen($value)<12 || strlen($value)>72) throw new ValidationException($field,'La contraseña debe tener entre 12 y 72 caracteres.'); return password_hash($value,PASSWORD_DEFAULT); }
}
