<?php if(!$detail): ?><p class="empty panel">Receta no encontrada.</p><?php else: $rx=$detail['prescription']; $items=$detail['items']; ?>
<article class="panel prescription<?= $rx['status']==='cancelled' ? ' is-cancelled':'' ?>">
<?php if($rx['status']==='cancelled'): ?><div class="prescription-stamp">ANULADA</div><?php endif; ?>
<header class="prescription-head">
<?php view('components/brand'); ?>
<div class="prescription-clinic-info">
<strong>YASIG ODONT · FAMILY DENTAL CARE</strong>
<span><?= e(setting('clinic_address',env('CLINIC_ADDRESS')) ?: 'Dirección pendiente de configurar') ?></span>
<span><?= e(setting('clinic_phone',env('CLINIC_PHONE')) ?: 'Teléfono pendiente') ?> · <?= e(setting('clinic_email',env('CLINIC_EMAIL')) ?: 'Correo pendiente') ?></span>
</div>
</header>
<div class="prescription-title"><span class="prescription-rx">℞</span><div><h2>Receta médica</h2><p class="muted">Folio #<?= (int)$rx['id'] ?> · <?= local_date($rx['created_at'],'d/m/Y H:i') ?></p></div></div>
<?php if(flag('APP_DEMO')): ?><p class="notice warning">ENTORNO DE DEMOSTRACIÓN — DATOS FICTICIOS. No es un documento médico válido.</p><?php endif; ?>
<div class="prescription-patient">
<div><span class="muted">Paciente</span><strong><?= e($rx['patient_name']) ?></strong></div>
<div><span class="muted">Código</span><strong><?= e($rx['patient_code']) ?></strong></div>
<div><span class="muted">Documento</span><strong><?= e($rx['patient_document']) ?></strong></div>
<div><span class="muted">Fecha de nacimiento</span><strong><?= $rx['birth_date'] ? e(date('d/m/Y',strtotime($rx['birth_date']))):'No registrada' ?></strong></div>
</div>
<?php if($rx['diagnosis_summary']): ?><div class="prescription-section"><span class="muted">Diagnóstico / motivo</span><p><?= nl2br(e($rx['diagnosis_summary'])) ?></p></div><?php endif; ?>
<div class="prescription-items">
<?php foreach($items as $i=>$item): ?>
<div class="prescription-item"><span class="prescription-item-number"><?= $i+1 ?></span><div>
<strong><?= e($item['drug_name']) ?><?= $item['presentation'] ? ' · '.e($item['presentation']):'' ?></strong>
<p><?= e($item['dosage']) ?> · <?= e($item['frequency']) ?><?= $item['duration'] ? ' · Durante '.e($item['duration']):'' ?><?= $item['route'] ? ' · Vía '.e($item['route']):'' ?></p>
<?php if($item['instructions']): ?><small><?= e($item['instructions']) ?></small><?php endif; ?>
</div></div>
<?php endforeach; ?>
</div>
<?php if($rx['instructions']): ?><div class="prescription-section"><span class="muted">Indicaciones generales</span><p><?= nl2br(e($rx['instructions'])) ?></p></div><?php endif; ?>
<?php if($rx['status']==='cancelled'): ?><div class="notice error">Receta anulada el <?= local_date($rx['cancelled_at']) ?>. Motivo: <?= e($rx['cancel_reason']) ?></div><?php endif; ?>
<footer class="prescription-sign">
<div class="prescription-sign-line"><strong><?= e($rx['dentist']) ?></strong><span><?= e($rx['dentist_specialty']) ?></span><small>Firma y sello profesional</small></div>
<div class="prescription-sign-line"><strong>&nbsp;</strong><span>&nbsp;</span><small>Emitida por <?= e($rx['author']) ?></small></div>
</footer>
<div class="actions"><a class="button" href="<?= e(url('/admin/prescription?id='.$rx['id'].'&pdf=1')) ?>" target="_blank">Ver PDF <?= icon('check',18) ?></a><a class="button secondary" href="<?= e(url('/admin/clinical?patient_id='.$rx['patient_id'])) ?>">Volver a la historia</a></div>
</article>
<?php endif; ?>
