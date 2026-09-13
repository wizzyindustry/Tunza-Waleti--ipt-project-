// assets/js/deposit.js - Tunza Waleti Deposit Page Interactive Logic

document.addEventListener('DOMContentLoaded', function () {
    // DOM Elements
    const plansSelect = document.getElementById('plans');
    const depositInput = document.getElementById('depositAmountInput');
    const liveAmountDisplay = document.getElementById('liveAmountDisplay');
    const presetChips = document.querySelectorAll('.preset-chip');
    const paymentRadios = document.querySelectorAll('input[name="payment_method"]');
    const paymentCards = document.querySelectorAll('.payment-card');
    const phoneNumberInput = document.getElementById('phoneNumber');

    // Summary Card Elements
    const summaryTargetPlan = document.getElementById('summaryTargetPlan');
    const summaryDepositAmount = document.getElementById('summaryDepositAmount');
    const summaryPaymentMethod = document.getElementById('summaryPaymentMethod');
    const summaryTotalAmount = document.getElementById('summaryTotalAmount');

    // Modal Elements
    const btnConfirm = document.getElementById('btnConfirmDeposit');
    const depositModal = document.getElementById('depositModal');
    const modalCloseBtn = document.getElementById('modalCloseBtn');
    const modalBody = document.getElementById('modalBody');
    const modalAmount = document.getElementById('modalAmount');

    // Format number to Tanzanian Shillings format (e.g. 50,000)
    function formatCurrency(amount) {
        const num = parseFloat(amount) || 0;
        return num.toLocaleString('en-US');
    }

    // Update Summary & Live Displays
    function updateDepositDisplay() {
        const val = parseFloat(depositInput.value) || 0;
        const formatted = formatCurrency(val);

        // Update live amount display
        if (liveAmountDisplay) {
            liveAmountDisplay.textContent = formatted;
        }

        // Update summary values
        if (summaryDepositAmount) {
            summaryDepositAmount.textContent = `TZS ${formatted}`;
        }
        if (summaryTotalAmount) {
            summaryTotalAmount.textContent = `TZS ${formatted}`;
        }

        // Update target plan text
        if (plansSelect && summaryTargetPlan) {
            const selectedOption = plansSelect.options[plansSelect.selectedIndex];
            if (selectedOption && selectedOption.value) {
                summaryTargetPlan.textContent = selectedOption.text.split('(')[0].trim();
            } else {
                summaryTargetPlan.textContent = 'General Savings';
            }
        }
    }

    // Handle Amount Input Change
    if (depositInput) {
        depositInput.addEventListener('input', function () {
            // Remove active class from preset chips if custom amount entered
            presetChips.forEach(chip => {
                if (parseFloat(chip.getAttribute('data-amount')) === parseFloat(depositInput.value)) {
                    chip.classList.add('active');
                } else {
                    chip.classList.remove('active');
                }
            });
            updateDepositDisplay();
        });
    }

    // Handle Preset Amount Chip Clicks
    presetChips.forEach(chip => {
        chip.addEventListener('click', function () {
            presetChips.forEach(c => c.classList.remove('active'));
            this.classList.add('active');

            const amount = this.getAttribute('data-amount');
            if (depositInput) {
                depositInput.value = amount;
                updateDepositDisplay();
            }
        });
    });

    // Handle Target Plan Selection Change
    if (plansSelect) {
        plansSelect.addEventListener('change', updateDepositDisplay);
    }

    // Handle Payment Provider Selection
    paymentRadios.forEach(radio => {
        radio.addEventListener('change', function () {
            paymentCards.forEach(card => card.classList.remove('selected'));
            const parentCard = this.closest('.payment-card');
            if (parentCard) {
                parentCard.classList.add('selected');
            }

            if (summaryPaymentMethod) {
                summaryPaymentMethod.textContent = this.value;
            }
        });
    });

    // Handle Deposit Confirmation Button
    if (btnConfirm && depositModal) {
        btnConfirm.addEventListener('click', function (e) {
            e.preventDefault();

            const amountVal = parseFloat(depositInput ? depositInput.value : 0);
            if (amountVal < 1000) {
                alert('Please enter a minimum deposit amount of TZS 1,000');
                return;
            }

            const phoneVal = phoneNumberInput ? phoneNumberInput.value.trim() : '';
            if (!phoneVal) {
                alert('Please enter a valid mobile money phone number.');
                return;
            }

            const selectedProvider = document.querySelector('input[name="payment_method"]:checked');
            const providerName = selectedProvider ? selectedProvider.value : 'Mobile Money';

            if (modalAmount) {
                modalAmount.textContent = `TZS ${formatCurrency(amountVal)}`;
            }

            // Reset modal to processing state
            modalBody.innerHTML = `
                <div class="modal-status-icon spinner-icon">
                    <i class="fas fa-spinner fa-spin"></i>
                </div>
                <h3 class="modal-title">Pushing Payment Request...</h3>
                <p class="modal-text">A payment prompt has been sent to <strong>+255 ${phoneVal}</strong> via <strong>${providerName}</strong>.</p>
                <p class="modal-subtext">Please check your phone screen and enter your PIN to approve the deposit of <strong>TZS ${formatCurrency(amountVal)}</strong>.</p>
                <div class="modal-progress-bar"><div class="modal-progress-fill"></div></div>
            `;

            depositModal.classList.add('active');

            // Simulate USSD approval completion after 3.5 seconds
            setTimeout(function () {
                modalBody.innerHTML = `
                    <div class="modal-status-icon success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="modal-title" style="color: #10b981;">Deposit Successful!</h3>
                    <p class="modal-text">Your deposit of <strong>TZS ${formatCurrency(amountVal)}</strong> has been received and credited to your wallet goal.</p>
                    <div class="modal-receipt-box">
                        <div class="receipt-line"><span>Reference ID:</span> <strong>TW-${Math.floor(100000 + Math.random() * 900000)}</strong></div>
                        <div class="receipt-line"><span>Target Plan:</span> <strong>${summaryTargetPlan.textContent}</strong></div>
                        <div class="receipt-line"><span>Provider:</span> <strong>${providerName}</strong></div>
                    </div>
                    <button class="btn-modal-done" id="btnModalDone">Done & View Dashboard</button>
                `;

                const btnDone = document.getElementById('btnModalDone');
                if (btnDone) {
                    btnDone.addEventListener('click', function () {
                        window.location.href = '/pages/dashboard.html';
                    });
                }
            }, 3800);
        });
    }

    // Modal Close Button
    if (modalCloseBtn && depositModal) {
        modalCloseBtn.addEventListener('click', function () {
            depositModal.classList.remove('active');
        });

        depositModal.addEventListener('click', function (e) {
            if (e.target === depositModal) {
                depositModal.classList.remove('active');
            }
        });
    }

    // Initialize display on load
    updateDepositDisplay();
});
