/**
 * W3 Pixel Server-Side Tracking Client Script
 *
 * Implements non-blocking CAPI dispatch (sendBeacon/fetch),
 * form data persistence for high EMQ, and AJAX Add to Cart synchronization.
 */

(function ($) {
    'use strict';

    if (typeof window.w3PixelOptions === 'undefined') {
        return;
    }

    var options = window.w3PixelOptions;

    var W3PixelTracker = {
        init: function () {
            this.initFormPersistence();
            this.initAjaxAddToCart();
            this.processQueuedEvents();
            this.fetchDynamicOptions();
        },

        // Helper to generate UUIDv4 client-side
        guidv4: function () {
            if (window.crypto && window.crypto.randomUUID) {
                return window.crypto.randomUUID();
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                var r = (Math.random() * 16) | 0;
                var v = c === 'x' ? r : (r & 0x3) | 0x8;
                return v.toString(16);
            });
        },

        // Cookie helper
        setCookie: function (name, value, days) {
            var expires = '';
            if (days) {
                var date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = '; expires=' + date.toUTCString();
            }
            var domain = options.cookieDomain ? '; domain=' + options.cookieDomain : '';
            document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/' + domain + '; SameSite=Lax';
        },

        getCookie: function (name) {
            var nameEQ = name + '=';
            var ca = document.cookie.split(';');
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                if (c.indexOf(nameEQ) === 0) return decodeURIComponent(c.substring(nameEQ.length, c.length));
            }
            return null;
        },

        // 1. Form Data Persistence: Captures early contact data on any form input
        initFormPersistence: function () {
            var self = this;
            var currentData = {};
            var raw = self.getCookie('w3_advanced_form_data');
            if (raw) {
                try {
                    currentData = JSON.parse(raw) || {};
                } catch (e) {
                    currentData = {};
                }
            }

            var saveField = function (field, value) {
                if (!value || typeof value !== 'string') return;
                value = value.trim();
                if (!value) return;

                if (field === 'email' && value.indexOf('@') > 0 && value.indexOf('.') > 0) {
                    currentData.email = value;
                } else if (field === 'phone' && value.length >= 7) {
                    currentData.phone = value;
                } else if (field === 'first_name' && value.length >= 2) {
                    currentData.first_name = value;
                } else if (field === 'last_name' && value.length >= 2) {
                    currentData.last_name = value;
                }

                self.setCookie('w3_advanced_form_data', JSON.stringify(currentData), 90);
            };

            $(document).on('change blur', 'input, select', function () {
                var $el = $(this);
                var type = ($el.attr('type') || '').toLowerCase();
                var name = ($el.attr('name') || '').toLowerCase();
                var id = ($el.attr('id') || '').toLowerCase();
                var val = $el.val();

                if (type === 'password' || type === 'hidden') return;

                if (type === 'email' || name.indexOf('email') !== -1 || id.indexOf('email') !== -1) {
                    saveField('email', val);
                } else if (type === 'tel' || name.indexOf('phone') !== -1 || id.indexOf('phone') !== -1) {
                    saveField('phone', val);
                } else if (name.indexOf('first_name') !== -1 || name.indexOf('billing_first_name') !== -1 || id.indexOf('first_name') !== -1) {
                    saveField('first_name', val);
                } else if (name.indexOf('last_name') !== -1 || name.indexOf('billing_last_name') !== -1 || id.indexOf('last_name') !== -1) {
                    saveField('last_name', val);
                }
            });
        },

        // 2. Asynchronous CAPI Dispatcher (sendBeacon -> fetch -> jQuery.ajax)
        sendServerEvent: function (eventName, eventId, customData) {
            var restUrl = options.restUrl || '/wp-json/w3-pixel-capi/v1/event';
            var payload = {
                event_name: eventName,
                event_id: eventId,
                event_source_url: window.location.href,
                referrer_url: document.referrer || '',
                custom_data: customData || {}
            };

            // Strategy 1: navigator.sendBeacon
            if (navigator.sendBeacon) {
                try {
                    var formData = new FormData();
                    formData.append('event_name', payload.event_name);
                    formData.append('event_id', payload.event_id);
                    formData.append('event_source_url', payload.event_source_url);
                    formData.append('referrer_url', payload.referrer_url);
                    formData.append('custom_data', JSON.stringify(payload.custom_data));

                    var sent = navigator.sendBeacon(restUrl, formData);
                    if (sent) {
                        if (options.debug) {
                            console.log('[W3 Pixel CAPI] Sent via sendBeacon:', payload);
                        }
                        return;
                    }
                } catch (e) {
                    // Fall through to fetch
                }
            }

            // Strategy 2: window.fetch
            if (window.fetch) {
                fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                }).then(function (res) {
                    if (options.debug) {
                        console.log('[W3 Pixel CAPI] Sent via fetch status:', res.status);
                    }
                }).catch(function (err) {
                    if (options.debug) {
                        console.warn('[W3 Pixel CAPI] Fetch error, attempting ajax fallback:', err);
                    }
                });
                return;
            }

            // Strategy 3: jQuery.ajax fallback
            $.ajax({
                type: 'POST',
                url: restUrl,
                data: JSON.stringify(payload),
                contentType: 'application/json',
                dataType: 'json'
            });
        },

        // 3. Process queued events generated by PHP
        processQueuedEvents: function () {
            var self = this;
            var events = window.w3PixelEvents || [];
            if (!events.length || !window.fbq) return;

            for (var i = 0; i < events.length; i++) {
                var evt = events[i] || {};
                if (!evt.event_name) continue;

                var params = evt.custom_data || {};
                var eventId = evt.event_id || self.guidv4();

                if (options.debug) {
                    console.log('[W3 Pixel] Firing Pixel Event:', evt.event_name, params, 'eventID:', eventId);
                }

                // Fire browser Pixel
                fbq('track', evt.event_name, params, { eventID: eventId });

                // Asynchronously dispatch to CAPI if not already sent by server
                if (evt.send_to_capi) {
                    self.sendServerEvent(evt.event_name, eventId, params);
                }
            }

            // Clear after processing
            window.w3PixelEvents = [];
        },

        // 4. Synchronize WooCommerce AJAX Add to Cart
        initAjaxAddToCart: function () {
            var self = this;
            $(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {
                if (!$button || !$button.length) return;

                var productId = $button.data('product_id') || $button.attr('data-product_id') || '';
                var quantity = $button.data('quantity') || $button.attr('data-quantity') || 1;
                var eventId = self.guidv4();

                var customData = {
                    content_ids: [String(productId)],
                    content_type: 'product',
                    num_items: parseInt(quantity, 10) || 1
                };

                if (window.fbq) {
                    if (options.debug) {
                        console.log('[W3 Pixel] AJAX AddToCart Pixel:', customData, 'eventID:', eventId);
                    }
                    fbq('track', 'AddToCart', customData, { eventID: eventId });
                }

                // Asynchronously dispatch to CAPI
                self.sendServerEvent('AddToCart', eventId, customData);
            });
        },

        // 5. Dynamic options for cached pages (WP Rocket / LiteSpeed / Cloudflare)
        fetchDynamicOptions: function () {
            if (!options.fetchDynamicOptions) return;

            var url = options.dynamicOptionsUrl || '/wp-json/w3-pixel-capi/v1/dynamic-options';
            if (window.fetch) {
                fetch(url).then(function (res) {
                    return res.json();
                }).then(function (data) {
                    if (data && data.advanced_matching && window.fbq && options.pixelId) {
                        // Re-initialize pixel with fresh dynamic advanced matching
                        fbq('init', options.pixelId, data.advanced_matching);
                    }
                }).catch(function () {});
            }
        }
    };

    $(document).ready(function () {
        W3PixelTracker.init();
    });

})(jQuery);
