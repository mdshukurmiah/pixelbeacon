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

            // Fallback helper to admin-ajax.php
            var sendAjaxFallback = function () {
                var ajaxUrl = options.ajaxUrl || '/wp-admin/admin-ajax.php';
                $.ajax({
                    type: 'POST',
                    url: ajaxUrl,
                    data: {
                        action: 'w3_pixel_capi_event',
                        event_name: payload.event_name,
                        event_id: payload.event_id,
                        event_source_url: payload.event_source_url,
                        referrer_url: payload.referrer_url,
                        custom_data: JSON.stringify(payload.custom_data)
                    },
                    dataType: 'json'
                });
            };

            // Strategy 2: window.fetch via REST API
            if (window.fetch) {
                fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                }).then(function (res) {
                    if (!res.ok) {
                        if (options.debug) {
                            console.warn('[W3 Pixel CAPI] REST endpoint returned ' + res.status + ', using admin-ajax fallback');
                        }
                        sendAjaxFallback();
                    } else if (options.debug) {
                        console.log('[W3 Pixel CAPI] Sent via fetch status:', res.status);
                    }
                }).catch(function (err) {
                    if (options.debug) {
                        console.warn('[W3 Pixel CAPI] Fetch error, using admin-ajax fallback:', err);
                    }
                    sendAjaxFallback();
                });
                return;
            }

            // Strategy 3: jQuery.ajax via admin-ajax.php
            sendAjaxFallback();
        },

        // 3. Process queued events generated by PHP
        processQueuedEvents: function () {
            var self = this;
            var events = window.w3PixelEvents || [];
            if (!events.length) return;

            for (var i = 0; i < events.length; i++) {
                var evt = events[i] || {};
                if (!evt.event_name) continue;

                var params = (evt.custom_data && typeof evt.custom_data === 'object' && !Array.isArray(evt.custom_data)) ? evt.custom_data : {};
                var eventId = evt.event_id || self.guidv4();

                // Fire browser Pixel only if not already tracked inline
                if (!evt.browser_tracked && window.fbq) {
                    if (options.debug) {
                        console.log('[W3 Pixel] Firing Pixel Event:', evt.event_name, params, 'eventID:', eventId);
                    }
                    var trackOpts = { eventID: eventId };
                    if (options.testEventCode) {
                        trackOpts.test_event_code = options.testEventCode;
                    }
                    fbq('track', evt.event_name, params, trackOpts);
                    evt.browser_tracked = true;
                }

                // Asynchronously dispatch to CAPI if flagged for client relay
                if (evt.send_to_capi && !evt.capi_dispatched) {
                    evt.capi_dispatched = true;
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
                // Priority 1: Use server-synchronized event data from cart fragments
                if (fragments && fragments.w3_fbpixel_event) {
                    var serverEvt = fragments.w3_fbpixel_event;
                    if (window.fbq && serverEvt.event_name && serverEvt.event_id) {
                        if (options.debug) {
                            console.log('[W3 Pixel] AJAX AddToCart Pixel (synchronized with CAPI):', serverEvt);
                        }
                        var syncOpts = { eventID: serverEvt.event_id };
                        if (options.testEventCode) {
                            syncOpts.test_event_code = options.testEventCode;
                        }
                        fbq('track', serverEvt.event_name, serverEvt.custom_data || {}, syncOpts);
                    }
                    // Server already sent CAPI with this exact event_id, so avoid duplicate CAPI dispatch
                    return;
                }

                // Fallback for custom AJAX implementations without standard fragments
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
                        console.log('[W3 Pixel] AJAX AddToCart Pixel Fallback:', customData, 'eventID:', eventId);
                    }
                    var fbOpts = { eventID: eventId };
                    if (options.testEventCode) {
                        fbOpts.test_event_code = options.testEventCode;
                    }
                    fbq('track', 'AddToCart', customData, fbOpts);
                }

                // Asynchronously dispatch fallback to CAPI
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
                    if (data && data.advanced_matching) {
                        options.advancedMatching = $.extend({}, options.advancedMatching || {}, data.advanced_matching);

                        if (window.fbq && options.pixelId) {
                            var isInitialized = false;
                            if (typeof window.fbq.getState === 'function') {
                                var state = window.fbq.getState();
                                var pixels = (state && state.pixels) || [];
                                isInitialized = pixels.some(function (p) {
                                    return String(p.id) === String(options.pixelId);
                                });
                            } else if (window.fbq.loaded) {
                                // Base code loaded in <head> already initialized the pixel
                                isInitialized = true;
                            }

                            // Only call fbq('init') if not already initialized to avoid Duplicate Pixel ID console error
                            if (!isInitialized) {
                                fbq('init', options.pixelId, data.advanced_matching);
                            }
                        }
                    }
                }).catch(function () { });
            }
        }
    };

    $(document).ready(function () {
        W3PixelTracker.init();
    });

})(jQuery);
