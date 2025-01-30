<?php

function cmplz_delay_banner() {
	ob_start(); ?>
	<script>
        document.addEventListener("cmplz_before_cookiebanner", function() {
            document.querySelector('#cmplz-cookiebanner-container').classList.add('cmplz-hidden');
        });
        
        document.addEventListener("cmplz_cookie_warning_loaded", function() {
            function showBannerOnScroll() {
                document.querySelector('#cmplz-cookiebanner-container').classList.remove('cmplz-hidden');
                window.removeEventListener('scroll', showBannerOnScroll);
            }
            window.addEventListener('scroll', showBannerOnScroll);
        });
	</script>
	<?php
	$script = ob_get_clean();
	$script = str_replace(array('<script>', '</script>'), '', $script);
	wp_add_inline_script( 'cmplz-cookiebanner', $script);
}
add_action( 'wp_enqueue_scripts', 'cmplz_delay_banner', PHP_INT_MAX );
