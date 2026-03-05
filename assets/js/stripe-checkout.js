(function($) {
    function debugLog(message) {
        if (window.stripeCheckoutData && window.stripeCheckoutData.debug) {
            console.log('[CIS Stripe Checkout Debug]', message);
        }
    }

    function initStripeCheckout() {
        debugLog('Initializing Stripe Checkout...');
        console.log('stripeCheckoutData:', window.stripeCheckoutData);
        
        try {
            const paymentForm = document.getElementById('payment-form');
            const modal = document.getElementById('payment-modal');
            let activeInvoiceId = null;
            let activeInvoiceAmount = null;
            let selectedPaymentMethod = 'card'; // Default payment method

            // Handle pay button clicks
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('pay-invoice-btn')) {
                    debugLog('Pay button clicked');
                    
                    activeInvoiceId = e.target.dataset.invoiceId;
                    activeInvoiceAmount = parseFloat(e.target.dataset.amount) || 0;
                    
                    // Reset payment method to default
                    selectedPaymentMethod = 'card';
                    const radioButtons = document.querySelectorAll('input[name="payment_method"]');
                    if (radioButtons.length > 0) {
                        radioButtons[0].checked = true;
                    }
                    
                    // Update payment details in modal
                    updatePaymentDetails(activeInvoiceAmount, selectedPaymentMethod);
                    
                    modal.style.display = 'block';
                }
            });

            // Handle payment method selection change
            document.addEventListener('change', function(e) {
                if (e.target.name === 'payment_method') {
                    selectedPaymentMethod = e.target.value;
                    debugLog('Payment method changed to: ' + selectedPaymentMethod);
                    updatePaymentDetails(activeInvoiceAmount, selectedPaymentMethod);
                }
            });

            // Function to update payment details based on payment method
            function updatePaymentDetails(amount, paymentMethod) {
                const modalContent = modal.querySelector('.modal-content');
                
                if (!document.getElementById('payment-details')) {
                    const newPaymentDetails = document.createElement('div');
                    newPaymentDetails.id = 'payment-details';
                    modalContent.insertBefore(newPaymentDetails, document.querySelector('.payment-method-selection'));
                }
                
                const detailsElement = document.getElementById('payment-details');
                
                // Display fee breakdown based on selected payment method
                let feeBreakdownHtml = '';
                let totalAmount = amount;
                let feeAmount = 0;
                
                if (paymentMethod === 'card' && window.stripeCheckoutData.convenienceFeePercentage > 0) {
                    // Calculate percentage-based fee for credit cards
                    feeAmount = (amount * (window.stripeCheckoutData.convenienceFeePercentage / 100)).toFixed(2);
                    totalAmount = (parseFloat(amount) + parseFloat(feeAmount)).toFixed(2);
                    
                    feeBreakdownHtml = `
                        <div class="fee-breakdown">
                            <div class="fee-row">
                                <span>Invoice Amount:</span>
                                <span>$${amount.toFixed(2)}</span>
                            </div>
                            <div class="fee-row">
                                <span>Convenience Fee (${window.stripeCheckoutData.convenienceFeePercentage}%):</span>
                                <span>$${feeAmount}</span>
                            </div>
                            <div class="fee-row total">
                                <span>Total:</span>
                                <span>$${totalAmount}</span>
                            </div>
                        </div>`;
                } else if (paymentMethod === 'ach' && window.stripeCheckoutData.achFeeAmount > 0) {
                    // Calculate flat fee for ACH payments
                    feeAmount = parseFloat(window.stripeCheckoutData.achFeeAmount).toFixed(2);
                    totalAmount = (parseFloat(amount) + parseFloat(feeAmount)).toFixed(2);
                    
                    feeBreakdownHtml = `
                        <div class="fee-breakdown">
                            <div class="fee-row">
                                <span>Invoice Amount:</span>
                                <span>$${amount.toFixed(2)}</span>
                            </div>
                            <div class="fee-row">
                                <span>ACH Processing Fee:</span>
                                <span>$${feeAmount}</span>
                            </div>
                            <div class="fee-row total">
                                <span>Total:</span>
                                <span>$${totalAmount}</span>
                            </div>
                        </div>`;
                } else {
                    // No fee
                    feeBreakdownHtml = `
                        <div class="fee-breakdown">
                            <div class="fee-row">
                                <span>Invoice Amount:</span>
                                <span>$${amount.toFixed(2)}</span>
                            </div>
                            <div class="fee-row total">
                                <span>Total:</span>
                                <span>$${amount.toFixed(2)}</span>
                            </div>
                        </div>`;
                }
                
                detailsElement.innerHTML = `
                    ${feeBreakdownHtml}
                    <p>Click "Proceed to Checkout" to complete your payment. You'll be redirected to a secure payment page.</p>
                `;
            }

            // Close modal
            try {
                document.querySelector('.close').addEventListener('click', function() {
                    modal.style.display = 'none';
                    
                    // Reset any error messages
                    const messageElement = document.getElementById('payment-message');
                    if (messageElement) {
                        messageElement.textContent = '';
                        messageElement.classList.remove('visible');
                    }
                });
            } catch (error) {
                console.error('Error setting up modal close listener:', error);
            }

            // Handle form submission
            if (paymentForm) {
                paymentForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    debugLog('Form submitted');
                    
                    const submitButton = document.getElementById('pay-button');
                    submitButton.disabled = true;
                    submitButton.textContent = 'Processing...';
                    
                    const messageElement = document.getElementById('payment-message');
                    messageElement.textContent = '';
                    messageElement.classList.remove('visible');
                    
                    try {
                        debugLog('Creating checkout session');
                        
                        // Create the form data for the AJAX request
                        const formData = new FormData();
                        formData.append('action', 'process_invoice_payment');
                        formData.append('invoice_id', activeInvoiceId);
                        formData.append('payment_method', selectedPaymentMethod);
                        formData.append('_ajax_nonce', stripeCheckoutData.nonce);
                        formData.append('return_url', window.location.href);

                        // Send the request to create a checkout session
                        const response = await fetch(stripeCheckoutData.ajaxUrl, {
                            method: 'POST',
                            body: formData
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();
                        debugLog('Server response:', data);

                        // If we received a checkout URL, redirect to it
                        if (data.success && data.data.checkout_url) {
                            window.location.href = data.data.checkout_url;
                        } else {
                            throw new Error(data.data || 'Error creating checkout session');
                        }
                        
                    } catch (error) {
                        console.error('Payment error:', error);
                        showErrorMessage(error.message);
                    } finally {
                        submitButton.disabled = false;
                        submitButton.textContent = 'Proceed to Stripe Checkout';
                    }
                });
            }

            function showErrorMessage(message) {
                const messageElement = document.getElementById('payment-message');
                if (messageElement) {
                    messageElement.textContent = message || 'An unexpected error occurred';
                    messageElement.classList.add('visible');
                } else {
                    alert('Payment error: ' + message);
                }
            }

        } catch (error) {
            console.error('Stripe Checkout initialization failed:', error);
            debugLog('Initialization error details: ' + error.message);
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        setTimeout(initStripeCheckout, 100);
    });
})(jQuery);