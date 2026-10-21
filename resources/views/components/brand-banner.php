<?php if (is_file(ROOT.'/public/assets/images/gallery/banner.jpeg')): ?>
<figure class="brand-banner <?= e($banner_class ?? '') ?>"><img src="<?= e(url('/assets/images/gallery/banner.jpeg')) ?>" width="1397" height="596" alt="Yasig Odont — Family Dental Care. Tu sonrisa, nuestro compromiso. Banner del consultorio con sus servicios y contacto." loading="<?= e($banner_loading ?? 'lazy') ?>"></figure>
<?php endif; ?>
