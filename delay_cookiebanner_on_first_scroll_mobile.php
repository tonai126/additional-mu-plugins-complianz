<?php

function cmplz_delay_banner_mobile() {
	ob_start(); ?>
	<script>
        function isMobile() {
            return window.matchMedia("(max-width: 768px)").matches;
        }
        
        document.addEventListener("cmplz_before_cookiebanner", function() {
            if (isMobile()) {
                document.querySelector('#cmplz-cookiebanner-container').classList.add('cmplz-hidden');
            }
        });
        
        document.addEventListener("cmplz_cookie_warning_loaded", function() {
            if (isMobile()) {
                function showBannerOnScroll() {
                    document.querySelector('#cmplz-cookiebanner-container').classList.remove('cmplz-hidden');
                    window.removeEventListener('scroll', showBannerOnScroll);
                }
                window.addEventListener('scroll', showBannerOnScroll);
            }
        });
	</script>
	<?php
	$script = ob_get_clean();
	$script = str_replace(array('<script>', '</script>'), '', $script);
	wp_add_inline_script( 'cmplz-cookiebanner', $script);
}
add_action( 'wp_enqueue_scripts', 'cmplz_delay_banner_mobile', PHP_INT_MAX );
