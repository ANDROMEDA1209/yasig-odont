<?php
declare(strict_types=1);
namespace App\Services;

/** Post/redirect/get destinations and replay protection, independent from clinical business rules. */
final class WorkspaceContext {
    private static ?string $requestFingerprint=null;
    private const QUERY_KEYS=['q','status','page','sort','direction','from','to','dentist','dentist_id','professional','type_key','treatment_type','date','mode','patient_id','version','view','edit','new','id','case_id','tab','control_id','type','finance_page','event_page','finance_type','finance_status','event_type','event_status','payment_method','patient','metric'];
    public static function query(array $query): array {
        $out=[];
        foreach(self::QUERY_KEYS as $key) if (isset($query[$key]) && is_scalar($query[$key]) && mb_strlen((string)$query[$key])<=200 && !preg_match('/[\x00-\x1F\\\\]/',(string)$query[$key])) $out[$key]=(string)$query[$key];
        return $out;
    }
    public static function url(string $path,array $overrides=[],array $drop=[]): string {
        $query=self::query($_GET);
        foreach($drop as $key) unset($query[$key]);
        foreach($overrides as $key=>$value) { if ($value===null) unset($query[$key]); else $query[$key]=(string)$value; }
        return url($path).($query ? '?'.http_build_query($query):'');
    }
    public static function destination(string $module,array $overrides=[],array $drop=[]): string {
        $path=url($module==='dashboard' ? '/admin':'/admin/'.$module);
        $raw=$_POST['_return_to'] ?? '';
        $parts=is_string($raw) && strlen($raw)<=3000 && !preg_match('/[\x00-\x20\\\\]/',$raw) ? parse_url($raw):false;
        $query=$_GET;
        // A return hint cannot change module, origin, export format, permission, or protocol.
        if (is_array($parts) && !isset($parts['scheme'],$parts['host']) && !isset($parts['scheme']) && !isset($parts['host']) && ($parts['path'] ?? '')===$path) {
            parse_str($parts['query'] ?? '',$query);
        }
        $query=self::query($query);
        foreach($drop as $key) unset($query[$key]);
        foreach($overrides as $key=>$value) { if ($value===null) unset($query[$key]); else $query[$key]=(string)$value; }
        return $path.($query ? '?'.http_build_query($query):'');
    }
    public static function feedback(string $state,array $errors=[]): void {
        $key=(string)($_POST['_form_key'] ?? '');
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_:.-]{0,159}$/D',$key)) $key='';
        $feedback=['state'=>$state,'form_key'=>$key,'errors'=>$errors,'values'=>[]];
        if ($state==='error') {
            foreach($_POST as $name=>$value) {
                if (str_starts_with((string)$name,'_') || preg_match('/password|token|request_key|confirmation/i',(string)$name)) continue;
                if (is_scalar($value) || (is_array($value) && count($value)<=100 && !array_filter($value,fn($v)=>!is_scalar($v)))) $feedback['values'][$name]=$value;
            }
            $GLOBALS['workspace_feedback']=$feedback;
        } else $_SESSION['workspace_feedback']=$feedback;
    }
    public static function errors(\Throwable $e): array {
        if ($e instanceof \App\Validators\ValidationException && $e->field!=='') return [$e->field=>$e->getMessage()];
        $message=$e->getMessage();
        // Business validation retains its existing message while locating the relevant visible control.
        $field=match(true) {
            str_contains($message,'documento') && str_contains($message,'existe')=>'document',
            str_contains($message,'correo') || str_contains($message,'Correo')=>'email',
            str_contains($message,'conflicto de horario') || str_contains($message,'Horario no disponible')=>'time',
            str_contains($message,'fecha de nacimiento')=>'birth_date',
            str_contains($message,'saldo') || str_contains($message,'Sobrepago')=>'amount',
            default=>'',
        };
        return $field ? [$field=>$message]:[];
    }
    private static function requestKey(): ?string {
        $key=$_POST['_submission_id'] ?? null;
        return is_string($key) && preg_match('/^[a-f0-9-]{24,64}$/D',$key) ? $key:null;
    }
    private static function fingerprint(): string {
        if (self::$requestFingerprint!==null) return self::$requestFingerprint;
        $data=$_POST; foreach(['_csrf','_return_to','_form_key','_submission_id'] as $name) unset($data[$name]);
        // File metadata and content distinguish a retry from another upload with a reused token.
        $files=[];
        foreach($_FILES as $name=>$file) {
            $paths=(array)($file['tmp_name'] ?? []); $hashes=[];
            foreach($paths as $path) $hashes[]=is_string($path) && is_uploaded_file($path) ? hash_file('sha256',$path):null;
            $files[$name]=[$file['name'] ?? '',$file['size'] ?? 0,$hashes];
        }
        return self::$requestFingerprint=hash('sha256',serialize([$_SERVER['REQUEST_URI'] ?? '',$data,$files]));
    }
    public static function begin(): void { self::$requestFingerprint=null; self::fingerprint(); }
    public static function replay(): ?string {
        $key=self::requestKey(); if (!$key) return null;
        $entries=$_SESSION['workspace_submissions'] ?? [];
        foreach($entries as $id=>$entry) if (($entry['at'] ?? 0)<time()-900) unset($entries[$id]);
        $_SESSION['workspace_submissions']=$entries;
        $entry=$entries[$key] ?? null; if (!$entry) return null;
        if (!hash_equals($entry['hash'],self::fingerprint())) throw new \DomainException('El formulario ya fue procesado con otros valores. Recarga el registro antes de enviar otra operación.');
        self::feedback('success'); return $entry['target'];
    }
    public static function completed(string $target): void {
        self::feedback('success'); $key=self::requestKey(); if (!$key) return;
        $entries=$_SESSION['workspace_submissions'] ?? [];
        $entries[$key]=['hash'=>self::fingerprint(),'target'=>$target,'at'=>time()];
        $_SESSION['workspace_submissions']=array_slice($entries,-50,null,true);
    }
}
