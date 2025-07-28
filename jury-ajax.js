jQuery(document).ready(function($) {
    // Star selection logic
    $('.crp-stars').each(function() {
        var $container = $(this);
        var $stars = $container.find('.crp-star');
        var $input = $container.find('input[type="hidden"]');
        var currentValue = parseInt($input.val()) || 0;

        function updateStars(val) {
            $stars.removeClass('selected');
            $stars.each(function(i) {
                if (i < val) $(this).addClass('selected');
            });
        }

        // Initial highlight
        updateStars(currentValue);

        $stars.on('mouseenter', function() {
            var idx = parseInt($(this).data('star')) || 0;
            updateStars(idx);
        }).on('mouseleave', function() {
            updateStars(currentValue);
        }).on('click', function() {
            var idx = parseInt($(this).data('star')) || 0;
            currentValue = idx;
            $input.val(idx); // Set hidden input!
            updateStars(currentValue);
            // Remove previous error for this field
            $container.next('.crp-star-error').remove();
        });
    });

    // Clear all old errors on new submit
    function clearAllStarErrors(form) {
        $(form).find('.crp-star-error').remove();
    }

    // Mandatory fields validation on form submit
    $('.crp-rating-form').on('submit', function(e) {
        clearAllStarErrors(this);
        var valid = true;
        $(this).find('.crp-stars').each(function() {
            var $container = $(this);
            var $input = $container.find('input[type="hidden"]');
            if (parseInt($input.val()) < 1) {
                valid = false;
                // Show a visible error
                if ($container.next('.crp-star-error').length === 0) {
                    $container.after('<span class="crp-star-error" style="color:red;font-size:13px;margin-left:10px;">Kötelező értékelni!</span>');
                }
            }
        });
        if (!valid) {
            e.preventDefault();
            alert('Kérjük, minden szempontot értékeljen legalább 1 csillaggal!');
            // Optional: Scroll to the form if validation fails
            var el = document.getElementById('rating-form');
            if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
        }
    });

    // Smooth scroll on page load if #rating-form in URL (after reload)
    if (window.location.hash === '#rating-form') {
        var el = document.getElementById('rating-form-anchor');
        if (el) {
            var offset = 80; // change as needed
            var top = $(el).offset().top - offset;
            $('html,body').animate({scrollTop: top}, 600);
        }
    }
});
