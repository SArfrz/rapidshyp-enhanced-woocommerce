jQuery(function($) {
    console.log('%c[Rapidshyp Enhanced JS] Initializing v4.0.0 (Loop Fix)', 'color: green; font-weight: bold;');

    // Helper to get state code from name (Essential for WooCommerce)
    function getStateCode(stateName) {
        // WooCommerce standard two-letter state codes for India
        const stateMap = {
            'Andaman and Nicobar Islands': 'AN', 'Andhra Pradesh': 'AP', 'Arunachal Pradesh': 'AR',
            'Assam': 'AS', 'Bihar': 'BR', 'Chandigarh': 'CH', 'Chhattisgarh': 'CT',
            'Dadra and Nagar Haveli': 'DN', 'Daman and Diu': 'DD', 'Delhi': 'DL', 'Goa': 'GA',
            'Gujarat': 'GJ', 'Haryana': 'HR', 'Himachal Pradesh': 'HP', 'Jammu and Kashmir': 'JK',
            'Jharkhand': 'JH', 'Karnataka': 'KA', 'Kerala': 'KL', 'Ladakh': 'LA',
            'Lakshadweep': 'LD', 'Madhya Pradesh': 'MP', 'Maharashtra': 'MH', 'Manipur': 'MN',
            'Meghalaya': 'ML', 'Mizoram': 'MZ', 'Nagaland': 'NL', 'Odisha': 'OR',
            'Puducherry': 'PY', 'Punjab': 'PB', 'Rajasthan': 'RJ', 'Sikkim': 'SK',
            'Tamil Nadu': 'TN',
            // Explicitly map both common variants to the confirmed working code 'TS'
            'Telangana': 'TS', 
            'Telengana': 'TS', 
            'Tripura': 'TR', 'Uttar Pradesh': 'UP',
            'Uttarakhand': 'UT', 'West Bengal': 'WB'
        };
        
        // Normalize the state name for comparison
        const normalizedStateName = stateName.toLowerCase().replace(/[^a-z0-9\s]/g, '').trim();

        // **Robust check: If the name contains "Telangana" or "Telengana", use 'TS'**
        if (normalizedStateName.includes('telangana') || normalizedStateName.includes('telengana')) {
            return 'TS'; 
        }

        // Now check the standard map for other states
        for (const key in stateMap) {
            const normalizedKey = key.toLowerCase().replace(/[^a-z0-9\s]/g, '').trim();

            if (normalizedKey === normalizedStateName) {
                return stateMap[key];
            }
        }
        
        // Final fallback: if no code found, return the original state name.
        return stateName;
    }
    
    // Utility to populate checkout fields from session (without triggering change)
    function populateFieldsFromSession() {
        try {
            const pincode = sessionStorage.getItem('sr_last_checked_pincode');
            const city = sessionStorage.getItem('sr_last_checked_city');
            const state = sessionStorage.getItem('sr_last_checked_state');

            const $billingPincode = $('#billing_postcode');
            const $shippingPincode = $('#shipping_postcode');
            const $billingCity = $('#billing_city');
            const $shippingCity = $('#shipping_city');
            const $billingState = $('#billing_state');
            const $shippingState = $('#shipping_state');

            if (pincode && /^\d{6}$/.test(pincode)) {
                if ($billingPincode.length && !$billingPincode.val()) {
                    $billingPincode.val(pincode);
                }
                if ($shippingPincode.length && !$shippingPincode.val() && $('#ship-to-different-address-checkbox').is(':checked')) {
                    $shippingPincode.val(pincode);
                }

                if (city) {
                    if ($billingCity.length && !$billingCity.val()) {
                        $billingCity.val(city);
                    }
                    if ($shippingCity.length && !$shippingCity.val()) {
                        $shippingCity.val(city);
                    }
                }

                if (state) {
                    const code = getStateCode(state);
                    if ($billingState.length && !$billingState.val()) {
                        $billingState.val(code);
                    }
                    if ($shippingState.length && !$shippingState.val()) {
                        $shippingState.val(code);
                    }
                }
            }
        } catch (e) {
            console.error('Error reading sessionStorage:', e);
        }
    }


    // --- Checkout Pincode Field Logic (Serviceability Check) ---

    function initializeCheckoutPincodeHandler() {
        const pincodeFields = '#billing_postcode, #shipping_postcode';
        const $form = $('form.checkout');
        let xhr = null;
        let debounceTimer = null; 
        
        // Checkout Validation Logic 
        $form.on('checkout_place_order', function() {
            const isServiceable = $form.data('is-serviceable');
            const checkedPincode = $form.data('checked-pincode');
            
            if (typeof isServiceable !== 'undefined' && isServiceable === false) {
                $form.find('.woocommerce-NoticeGroup--checkout').remove(); 
                
                const message = 'Delivery is **NOT** available to Pincode **' + checkedPincode + '**. Please enter a valid pincode to proceed.';
                
                $form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup--checkout"><ul class="woocommerce-error" role="alert"><li>' + message + '</li></ul></div>');
                
                $('html, body').animate({
                    scrollTop: ($('.woocommerce-NoticeGroup').offset().top - 100)
                }, 500);
                
                return false; 
            }
            return true; 
        });

        // Event handler for Pincode input/change
        $(document.body).on('input change', pincodeFields, function() {
            const $pincodeField = $(this);
            const pincode = $pincodeField.val().trim();
            const fieldType = $pincodeField.attr('id').includes('billing') ? 'billing' : 'shipping';
            let $resultDiv = $pincodeField.closest('.form-row').find('.sr-checkout-pincode-result');

            if (debounceTimer) { clearTimeout(debounceTimer); }
            if (xhr) { xhr.abort(); }

            if ($resultDiv.length === 0) {
                $pincodeField.closest('.form-row').append('<div class="sr-checkout-pincode-result" style="font-size: 0.9em; margin-top: 5px;"></div>');
                $resultDiv = $pincodeField.closest('.form-row').find('.sr-checkout-pincode-result');
            }

            if (!/^\d{6}$/.test(pincode)) {
                $resultDiv.empty();
                $form.data('is-serviceable', false).data('checked-pincode', pincode);
                $('#' + fieldType + '_city').val('');
                $('#' + fieldType + '_state').val('');
                sessionStorage.removeItem('sr_last_checked_pincode');
                $('body').trigger('update_checkout');
                return;
            }

            debounceTimer = setTimeout(function() {
                $resultDiv.html('<span style="color: #333;">Checking serviceability...</span>');

                xhr = $.ajax({
                    url: sr_ajax.ajax_url,
                    method: 'POST',
                    data: { action: 'sr_fetch_city_state_by_pincode', pincode: pincode, security: sr_ajax.nonce },
                    success: function(response) {
                        const isServiceable = response.data.is_serviceable;
                        const resultColor = isServiceable ? '#5cb85c' : '#d9534f';
                        
                        $resultDiv.html('<span style="color: ' + resultColor + ';">' + response.data.message + '</span>');
                        $form.data('is-serviceable', isServiceable).data('checked-pincode', pincode);

                        // Autofill City 
                        if (response.data.city) {
                            $('#' + fieldType + '_city').val(response.data.city); 
                            sessionStorage.setItem('sr_last_checked_city', response.data.city);
                        } else {
                            $('#' + fieldType + '_city').val('');
                            sessionStorage.removeItem('sr_last_checked_city');
                        }

                        // Autofill State
                        if (response.data.state) {
                            const stateCode = getStateCode(response.data.state);
                            $('#' + fieldType + '_state').val(stateCode); 
                            sessionStorage.setItem('sr_last_checked_state', response.data.state);
                        } else {
                            $('#' + fieldType + '_state').val(''); 
                            sessionStorage.removeItem('sr_last_checked_state');
                        }
                        
                        sessionStorage.setItem('sr_last_checked_pincode', pincode);
                        
                        // FIX: Explicitly trigger change on state once to update the Select2 component and Woo session
                        $('#' + fieldType + '_state').trigger('change'); 

                        // Trigger the single, controlled checkout update
                        $('body').trigger('update_checkout');
                    },
                    error: function(jqXHR) {
                        if (jqXHR.statusText !== 'abort') {
                            $resultDiv.html('<span style="color: #d9534f;">API Error. Check Failed.</span>');
                            $form.data('is-serviceable', false).data('checked-pincode', pincode);
                            $('#' + fieldType + '_city').val('');
                            $('#' + fieldType + '_state').val('');
                            $('body').trigger('update_checkout');
                        }
                    },
                    complete: function() {
                        xhr = null;
                    }
                });
            }, 500);
        });
    }

    // --- Utility and Initialization ---

    if ($('body').is('.woocommerce-cart, .woocommerce-checkout')) {
        initializeCheckoutPincodeHandler();
        populateFieldsFromSession(); 

        // Initial trigger for existing pincode validation
        setTimeout(function() {
            const $billingPincode = $('#billing_postcode');
            const $shippingPincode = $('#shipping_postcode');

            if ($billingPincode.val()) {
                $billingPincode.trigger('change');
            }
            if ($('#ship-to-different-address-checkbox').is(':checked') && $shippingPincode.val()) {
                $shippingPincode.trigger('change');
            }
        }, 500);

        // **LOOP FIX:** Re-apply values but DO NOT trigger change on state, 
        // which was causing the infinite update loop.
        $(document.body).on('updated_checkout updated_cart_totals', function() {
            populateFieldsFromSession();

            // Use the Select2-specific event 'selectWoo:select' for visual correction if needed,
            // as it is less likely to trigger a new checkout calculation loop than a generic 'change'.
            const $billingState = $('#billing_state');
            const $shippingState = $('#shipping_state');
            if ($billingState.val()) {
                 $billingState.trigger('selectWoo:select');
            }
            if ($('#ship-to-different-address-checkbox').is(':checked') && $shippingState.val()) {
                 $shippingState.trigger('selectWoo:select');
            }
        });

        $('#ship-to-different-address-checkbox').on('change', function() {
            setTimeout(function() {
                const $shippingPincode = $('#shipping_postcode');
                if ($shippingPincode.val()) {
                    $shippingPincode.trigger('change');
                }
            }, 300);
        });
    }

    // --- Cart Page Logic (Message Restoration) ---

    function updateWooCommerceSessionAndRecalculate(pincode, city, state) {
        $.ajax({
            url: sr_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'sr_set_customer_shipping_details',
                pincode: pincode,
                city: city,
                state: state,
                security: sr_ajax.nonce
            },
            success: function() {
                // This AJAX call updates the session, now trigger Woo update
                $('body').trigger('update_checkout'); 
            },
            error: function() {
                console.error('Failed to update WooCommerce session.');
            }
        });
    }

    const $pincodeInput = $('#sr_cart_pincode');
    const $result = $('#sr_cart_pincode_result');

    $('#sr_cart_pincode_btn').on('click', function() {
        const pin = $pincodeInput.val().trim();
        if (!/^\d{6}$/.test(pin)) {
            $result.html('<span style="color:red;">Invalid Pincode</span>');
            return;
        }

        $result.text('Checking...');
        
        $.ajax({
            url: sr_ajax.ajax_url,
            method: 'POST',
            data: { action: 'sr_fetch_city_state_by_pincode', pincode: pin, security: sr_ajax.nonce },
            success: function(response) {
                const isServiceable = response.data.is_serviceable;
                const resultColor = isServiceable ? '#5cb85c' : '#d9534f';
                const cityState = (response.data.city && response.data.state) ? ` (${response.data.city}, ${response.data.state})` : '';

                const statusText = response.data.message;
                const finalMessageHtml = '<span style="color: ' + resultColor + ';">' + statusText + cityState + '</span>';
                
                $result.html(finalMessageHtml); 
                sessionStorage.setItem('sr_last_checked_result_html', finalMessageHtml);
                    
                sessionStorage.setItem('sr_last_checked_pincode', pin);
                if (response.data.city) {
                    sessionStorage.setItem('sr_last_checked_city', response.data.city);
                }
                if (response.data.state) {
                    sessionStorage.setItem('sr_last_checked_state', response.data.state);
                }
                    
                updateWooCommerceSessionAndRecalculate(pin, response.data.city, response.data.state);
                    
            },
            error: function() {
                $result.html('<span style="color:red;">Error. Try again.</span>');
            }
        });
    });

    // Listener to restore the message after the cart is updated (redrawn)
    $(document.body).on('updated_cart_totals', function() {
        const savedPin = sessionStorage.getItem('sr_last_checked_pincode');
        if (savedPin) {
            $('#sr_cart_pincode').val(savedPin);
        }

        const savedResultHtml = sessionStorage.getItem('sr_last_checked_result_html');
        const $resultElement = $('#sr_cart_pincode_result');
        
        if (savedResultHtml && $resultElement.length) {
            $resultElement.html(savedResultHtml);
            sessionStorage.removeItem('sr_last_checked_result_html'); 
        }
    });
});