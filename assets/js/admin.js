/**
 * PixelBeacon for WooCommerce Admin JavaScript
 *
 * Implements tab switching (Facebook & TikTok), password toggles,
 * credentials validation, and API connection testing.
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        initTabs();
        initPasswordToggles();
        initFormValidation();
        initFacebookTestConnection();
        initTikTokTestConnection();
        initEventDependencies();

        /**
         * Initialize tab navigation (Facebook & TikTok)
         */
        function initTabs() {
            var $tabs = $('.pixelbeacon-wrap .nav-tab');
            if (!$tabs.length) return;

            var activateTab = function (tabName) {
                if (!tabName) tabName = 'facebook';

                $tabs.removeClass('nav-tab-active');
                $tabs.filter('[data-tab="' + tabName + '"]').addClass('nav-tab-active');

                // Switch main form panels
                $('.pixelbeacon-tab-panel').hide().removeClass('active');
                $('#pixelbeacon-tab-' + tabName).show().addClass('active');

                // Switch secondary tools (connection test & logs)
                $('.pixelbeacon-tab-tools').hide().removeClass('active');
                $('#pixelbeacon-tools-' + tabName).show().addClass('active');

                // Switch sidebar documentation & guides
                $('.pixelbeacon-sidebar-panel').hide().removeClass('active');
                $('#pixelbeacon-sidebar-' + tabName).show().addClass('active');

                // Sync hidden input and local storage
                $('#pixelbeacon_active_tab').val(tabName);
                try {
                    localStorage.setItem('pixelbeacon_active_tab', tabName);
                } catch (e) { }
            };

            $tabs.on('click', function (e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                activateTab(tab);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, null, '#tab-' + tab);
                }
            });

            // Restore tab from URL hash or localStorage
            var hash = window.location.hash;
            var storedTab = null;
            try {
                storedTab = localStorage.getItem('pixelbeacon_active_tab');
            } catch (e) { }

            if (hash === '#tab-tiktok' || hash === '#tiktok') {
                activateTab('tiktok');
            } else if (hash === '#tab-facebook' || hash === '#facebook') {
                activateTab('facebook');
            } else if (storedTab === 'tiktok') {
                activateTab('tiktok');
            } else {
                activateTab('facebook');
            }
        }

        /**
         * Initialize password field toggles (Facebook & TikTok)
         */
        function initPasswordToggles() {
            var setupToggle = function ($field) {
                if (!$field.length || $field.data('toggle-initialized')) return;

                var $wrapper = $('<div class="pixelbeacon-password-wrapper"></div>');
                var $btn = $('<button type="button" class="button button-secondary">Show</button>');

                $field.wrap($wrapper);
                $field.after($btn);
                $field.data('toggle-initialized', true);

                $btn.on('click', function (e) {
                    e.preventDefault();
                    if ($field.attr('type') === 'password') {
                        $field.attr('type', 'text');
                        $btn.text('Hide');
                    } else {
                        $field.attr('type', 'password');
                        $btn.text('Show');
                    }
                });
            };

            setupToggle($('#access_token'));
            setupToggle($('#tiktok_access_token'));
        }

        /**
         * Initialize form validation
         */
        function initFormValidation() {
            var $form = $('form[action="options.php"]');
            if (!$form.length) return;

            $form.on('submit', function (e) {
                var errors = [];

                // Validate Facebook settings if filled
                var pixelId = $('#pixel_id').val() ? $('#pixel_id').val().trim() : '';
                if (pixelId && !/^[0-9]{15,16}$/.test(pixelId)) {
                    errors.push('Facebook Pixel ID must be 15-16 digits.');
                }

                var accessToken = $('#access_token').val() ? $('#access_token').val().trim() : '';
                if (accessToken && accessToken.length < 50) {
                    errors.push('Facebook Conversions API Access Token appears too short.');
                }

                // Validate TikTok settings if enabled or filled
                var ttEnabled = $('#tiktok_enabled').is(':checked');
                var ttPixelId = $('#tiktok_pixel_id').val() ? $('#tiktok_pixel_id').val().trim() : '';
                var ttAccessToken = $('#tiktok_access_token').val() ? $('#tiktok_access_token').val().trim() : '';

                if (ttEnabled) {
                    if (!ttPixelId) {
                        errors.push('TikTok Pixel ID is required when TikTok Tracking is enabled.');
                    }
                    if (!ttAccessToken) {
                        errors.push('TikTok Events API Access Token is required when TikTok Tracking is enabled.');
                    }
                }

                if (ttPixelId && !/^[A-Za-z0-9]{16,20}$/.test(ttPixelId)) {
                    errors.push('TikTok Pixel ID must be 16-20 alphanumeric characters (e.g. C1234567890ABCDEF).');
                }

                if (ttAccessToken && ttAccessToken.length < 32) {
                    errors.push('TikTok Events API Access Token appears too short.');
                }

                if (errors.length) {
                    e.preventDefault();
                    alert('Please correct the following issues:\n\n• ' + errors.join('\n• '));
                }
            });
        }

        /**
         * Initialize Facebook test connection functionality
         */
        function initFacebookTestConnection() {
            var $testBtn = $('input[name="test_connection"]');
            if (!$testBtn.length) return;

            $testBtn.on('click', function () {
                var $this = $(this);
                var originalText = $this.val();

                var pixelId = $('#pixel_id').val() ? $('#pixel_id').val().trim() : '';
                var accessToken = $('#access_token').val() ? $('#access_token').val().trim() : '';

                if (!pixelId || !accessToken) {
                    alert('Please enter both Facebook Pixel ID and Access Token before testing the connection.');
                    return false;
                }

                $('#test_pixel_id').val(pixelId);
                $('#test_access_token').val(accessToken);

                $this.val('Testing Meta CAPI...').prop('disabled', true);

                var $form = $('#pixelbeacon_test_connection_form');
                $form.submit();

                setTimeout(function () {
                    $this.val(originalText).prop('disabled', false);
                }, 4000);

                return false;
            });
        }

        /**
         * Initialize TikTok test connection functionality
         */
        function initTikTokTestConnection() {
            var $testBtn = $('input[name="test_tiktok_connection"]');
            if (!$testBtn.length) return;

            $testBtn.on('click', function () {
                var $this = $(this);
                var originalText = $this.val();

                var pixelId = $('#tiktok_pixel_id').val() ? $('#tiktok_pixel_id').val().trim() : '';
                var accessToken = $('#tiktok_access_token').val() ? $('#tiktok_access_token').val().trim() : '';

                if (!pixelId || !accessToken) {
                    alert('Please enter both TikTok Pixel ID and Events API Access Token before testing the connection.');
                    return false;
                }

                $('#test_tiktok_pixel_id').val(pixelId);
                $('#test_tiktok_access_token').val(accessToken);

                $this.val('Testing TikTok API...').prop('disabled', true);

                var $form = $('#pixelbeacon_test_tiktok_connection_form');
                $form.submit();

                setTimeout(function () {
                    $this.val(originalText).prop('disabled', false);
                }, 4000);

                return false;
            });
        }

        /**
         * Handle event checkbox dependencies
         */
        function initEventDependencies() {
            // Auto-disable WooCommerce-only events if WooCommerce is not active
            if ($('.pixelbeacon-wrap').text().indexOf('WooCommerce Status: Not Active') !== -1) {
                $('input[name*="[enabled_events]"], input[name*="[tiktok_enabled_events]"]').each(function () {
                    var name = $(this).attr('name') || '';
                    if (name.indexOf('PageView') === -1 && name.indexOf('Lead') === -1 && name.indexOf('Contact') === -1 && name.indexOf('CompleteRegistration') === -1) {
                        $(this).prop('disabled', true).closest('label').css('color', '#999');
                    }
                });
            }
        }
    });

})(jQuery);
