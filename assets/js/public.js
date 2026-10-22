/**
 * PixelBeacon for WooCommerce Client Tracking Script
 *
 * Implements non-blocking CAPI dispatch (sendBeacon/fetch/ajax),
 * form data persistence for high EMQ, synchronized AJAX Add to Cart,
 * and dual-platform tracking for Meta Pixel and TikTok Pixel with RFC UUIDv4 deduplication.
 */

(function ($) {
    'use strict';

    var options = window.pixelBeaconOptions || window.w3PixelOptions;
    if (typeof options === 'undefined') {
        return;
    }

    var PixelBeaconTracker = {
        init: function () {
            this.initTikTokBase();
            this.initFormPersistence();
            this.initAjaxAddToCart();
            this.initClientEvents();
            this.processQueuedEvents();
            this.fetchDynamicOptions();
        },

        // Ensure TikTok Pixel is initialized client-side if loaded asynchronously
        initTikTokBase: function () {
            if (!options.tiktokEnabled || !options.tiktokPixelId) return;

            if (options.tiktokAdvancedMatching && window.ttq && typeof window.ttq.identify === 'function') {
                try {
                    window.ttq.identify(options.tiktokAdvancedMatching);
                } catch (e) { }
            }
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
            var raw = self.getCookie('pixelbeacon_form_data') || self.getCookie('w3_advanced_form_data');
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

                self.setCookie('pixelbeacon_form_data', JSON.stringify(currentData), 90);
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

        // 2. Asynchronous Server Dispatcher (sendBeacon -> fetch -> jQuery.ajax)
        // Dispatches to server-side endpoint which forwards to both Meta CAPI and TikTok Events API
        sendServerEvent: function (eventName, eventId, customData) {
            var restUrl = options.restUrl || '/wp-json/pixelbeacon/v1/event';
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
                            console.log('[PixelBeacon] Sent server event via sendBeacon:', payload);
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
                        action: 'pixelbeacon_event',
                        nonce: options.nonce || '',
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
                            console.warn('[PixelBeacon] REST endpoint returned ' + res.status + ', using admin-ajax fallback');
                        }
                        sendAjaxFallback();
                    } else if (options.debug) {
                        console.log('[PixelBeacon] Sent server event via fetch status:', res.status);
                    }
                }).catch(function (err) {
                    if (options.debug) {
                        console.warn('[PixelBeacon] Fetch error, using admin-ajax fallback:', err);
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
            var events = (window.pixelBeaconEvents || []).concat(window.w3PixelEvents || []);
            if (!events.length) return;

            for (var i = 0; i < events.length; i++) {
                var evt = events[i] || {};
                if (!evt.event_name) continue;

                var params = (evt.custom_data && typeof evt.custom_data === 'object' && !Array.isArray(evt.custom_data)) ? evt.custom_data : {};
                var eventId = evt.event_id || self.guidv4();

                // Fire Facebook Pixel only if not already tracked inline
                if (!evt.browser_tracked && window.fbq && options.pixelId) {
                    if (options.debug) {
                        console.log('[PixelBeacon] Firing Facebook Pixel Event:', evt.event_name, params, 'eventID:', eventId);
                    }
                    var trackOpts = { eventID: eventId };
                    if (options.testEventCode) {
                        trackOpts.test_event_code = options.testEventCode;
                    }
                    fbq('track', evt.event_name, params, trackOpts);
                    evt.browser_tracked = true;
                }

                // Fire TikTok Pixel with identical event_id deduplication
                if (!evt.tiktok_tracked && options.tiktokEnabled && window.ttq && typeof window.ttq.track === 'function' && evt.tiktok_event_name) {
                    var ttParams = (evt.tiktok_properties && typeof evt.tiktok_properties === 'object') ? evt.tiktok_properties : params;
                    var ttOpts = { event_id: eventId };
                    if (options.debug) {
                        console.log('[PixelBeacon] Firing TikTok Pixel Event:', evt.tiktok_event_name, ttParams, 'event_id:', eventId);
                    }
                    window.ttq.track(evt.tiktok_event_name, ttParams, ttOpts);
                    evt.tiktok_tracked = true;
                }

                // Asynchronously dispatch to server API if flagged for client relay
                if (evt.send_to_capi && !evt.capi_dispatched) {
                    evt.capi_dispatched = true;
                    self.sendServerEvent(evt.event_name, eventId, params);
                }
            }

            // Clear after processing
            window.pixelBeaconEvents = [];
            window.w3PixelEvents = [];
        },

        // 4. Synchronize WooCommerce AJAX Add to Cart
        initAjaxAddToCart: function () {
            var self = this;
            $(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {
                // Priority 1: Use server-synchronized event data from cart fragments
                var serverEvt = fragments && (fragments.pixelbeacon_event || fragments.w3_fbpixel_event);
                if (serverEvt) {
                    // Facebook Pixel tracking
                    if (window.fbq && serverEvt.event_name && serverEvt.event_id && options.pixelId) {
                        if (options.debug) {
                            console.log('[PixelBeacon] AJAX AddToCart Facebook Pixel (synchronized with CAPI):', serverEvt);
                        }
                        var syncOpts = { eventID: serverEvt.event_id };
                        if (options.testEventCode) {
                            syncOpts.test_event_code = options.testEventCode;
                        }
                        fbq('track', serverEvt.event_name, serverEvt.custom_data || {}, syncOpts);
                    }

                    // TikTok Pixel tracking with matching event_id
                    if (options.tiktokEnabled && window.ttq && typeof window.ttq.track === 'function' && serverEvt.event_id) {
                        var ttName = serverEvt.tiktok_event_name || 'AddToCart';
                        var ttParams = serverEvt.tiktok_properties || serverEvt.custom_data || {};
                        var ttOpts = { event_id: serverEvt.event_id };
                        if (options.debug) {
                            console.log('[PixelBeacon] AJAX AddToCart TikTok Pixel (synchronized with CAPI):', ttName, ttParams, ttOpts);
                        }
                        window.ttq.track(ttName, ttParams, ttOpts);
                    }

                    // Server already dispatched CAPI with this exact event_id
                    return;
                }

                // Fallback for custom AJAX implementations without standard cart fragments
                if (!$button || !$button.length) return;

                var productId = $button.data('product_id') || $button.attr('data-product_id') || '';
                var quantity = $button.data('quantity') || $button.attr('data-quantity') || 1;
                var eventId = self.guidv4();

                var customData = {
                    content_ids: [String(productId)],
                    content_type: 'product',
                    num_items: parseInt(quantity, 10) || 1
                };

                // Facebook Pixel fallback
                if (window.fbq && options.pixelId) {
                    if (options.debug) {
                        console.log('[PixelBeacon] AJAX AddToCart Facebook Fallback:', customData, 'eventID:', eventId);
                    }
                    var fbOpts = { eventID: eventId };
                    if (options.testEventCode) {
                        fbOpts.test_event_code = options.testEventCode;
                    }
                    fbq('track', 'AddToCart', customData, fbOpts);
                }

                // TikTok Pixel fallback
                if (options.tiktokEnabled && window.ttq && typeof window.ttq.track === 'function') {
                    var ttFallbackParams = {
                        contents: [{
                            content_id: String(productId),
                            content_type: 'product',
                            quantity: parseInt(quantity, 10) || 1
                        }],
                        content_type: 'product'
                    };
                    if (options.debug) {
                        console.log('[PixelBeacon] AJAX AddToCart TikTok Fallback:', ttFallbackParams, 'event_id:', eventId);
                    }
                    window.ttq.track('AddToCart', ttFallbackParams, { event_id: eventId });
                }

                // Asynchronously dispatch fallback to server APIs
                self.sendServerEvent('AddToCart', eventId, customData);
            });
        },

        // 5. Interactive client-side events (CustomizeProduct, AddToWishlist, AddPaymentInfo)
        initClientEvents: function () {
            var self = this;

            // CustomizeProduct: fires when variation is chosen on single product page
            $(document).on('found_variation', '.variations_form', function (event, variation) {
                if (!variation || !variation.variation_id) return;
                var eventId = self.guidv4();
                var params = {
                    content_name: (variation.variation_description || $('h1.product_title').text() || '').trim(),
                    content_ids: [String(variation.variation_id)],
                    content_type: 'product',
                    value: parseFloat(variation.display_price) || 0,
                    currency: options.currency || 'USD'
                };

                // Facebook
                if (window.fbq && options.pixelId) {
                    var trackOpts = { eventID: eventId };
                    if (options.testEventCode) trackOpts.test_event_code = options.testEventCode;
                    fbq('track', 'CustomizeProduct', params, trackOpts);
                }

                // TikTok (mapped to ViewContent with variation details)
                if (options.tiktokEnabled && window.ttq && typeof window.ttq.track === 'function') {
                    window.ttq.track('ViewContent', {
                        contents: [{
                            content_id: String(variation.variation_id),
                            content_name: params.content_name,
                            content_type: 'product',
                            price: params.value
                        }],
                        content_type: 'product',
                        value: params.value,
                        currency: params.currency
                    }, { event_id: eventId });
                }

                self.sendServerEvent('CustomizeProduct', eventId, params);
            });

            // AddToWishlist: clicks on wishlist buttons
            $(document).on('click', '.add_to_wishlist, .yith-wcwl-add-button a, .tinvwl_add_to_wishlist_button', function () {
                var $btn = $(this);
                var pid = $btn.data('product-id') || $btn.attr('data-product-id') || '';
                if (!pid) return;
                var eventId = self.guidv4();
                var params = {
                    content_name: ($btn.closest('.product').find('.woocommerce-loop-product__title, .product_title').text() || '').trim(),
                    content_ids: [String(pid)],
                    content_type: 'product'
                };

                // Facebook
                if (window.fbq && options.pixelId) {
                    var trackOpts = { eventID: eventId };
                    if (options.testEventCode) trackOpts.test_event_code = options.testEventCode;
                    fbq('track', 'AddToWishlist', params, trackOpts);
                }

                // TikTok
                if (options.tiktokEnabled && window.ttq && typeof window.ttq.track === 'function') {
                    window.ttq.track('AddToWishlist', {
                        contents: [{
                            content_id: String(pid),
                            content_name: params.content_name,
                            content_type: 'product'
                        }],
                        content_type: 'product'
                    }, { event_id: eventId });
                }

                self.sendServerEvent('AddToWishlist', eventId, params);
            });

            // AddPaymentInfo: triggered when user changes payment method on checkout
            $(document.body).on('change', 'input[name="payment_method"]', function () {
                var method = $(this).val();
                if (!method) return;
                var eventId = self.guidv4();
                var params = {
                    payment_method: method
                };

                // Facebook
                if (window.fbq && options.pixelId) {
                    var trackOpts = { eventID: eventId };
                    if (options.testEventCode) trackOpts.test_event_code = options.testEventCode;
                    fbq('track', 'AddPaymentInfo', params, trackOpts);
                }

                // TikTok
                if (options.tiktokEnabled && window.ttq && typeof window.ttq.track === 'function') {
                    window.ttq.track('AddPaymentInfo', {
                        payment_method: method
                    }, { event_id: eventId });
                }

                self.sendServerEvent('AddPaymentInfo', eventId, params);
            });
        },

        // 6. Dynamic options for cached pages (WP Rocket / LiteSpeed / Cloudflare)
        fetchDynamicOptions: function () {
            if (!options.fetchDynamicOptions) return;

            var url = options.dynamicOptionsUrl || '/wp-json/pixelbeacon/v1/dynamic-options';
            if (window.fetch) {
                fetch(url).then(function (res) {
                    return res.json();
                }).then(function (data) {
                    if (!data) return;

                    // Facebook dynamic advanced matching
                    if (data.advanced_matching) {
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
                                isInitialized = true;
                            }

                            if (!isInitialized) {
                                fbq('init', options.pixelId, data.advanced_matching);
                            }
                        }
                    }

                    // TikTok dynamic advanced matching
                    if (data.tiktok_advanced_matching && options.tiktokEnabled && window.ttq && typeof window.ttq.identify === 'function') {
                        try {
                            window.ttq.identify(data.tiktok_advanced_matching);
                        } catch (e) { }
                    }
                }).catch(function () { });
            }
        }
    };

    window.PixelBeaconTracker = PixelBeaconTracker;
    window.W3PixelTracker = PixelBeaconTracker;

    $(document).ready(function () {
        PixelBeaconTracker.init();
    });

})(jQuery);
