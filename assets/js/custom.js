/* MDCAN Cooperative - Custom Scripts */

$(document).ready(function () {
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function () {
        $('.alert-dismissible').fadeOut('slow');
    }, 5000);

    // Loan type: update max amount hint dynamically
    const loanLimits = {
        emergency: 200000,
        soft: 500000,
        essential_commodities: 500000,
        minor_tangible: 999999,
        major_tangible: 5000000
    };
    const loanDurations = {
        emergency: [1, 4],
        soft: [1, 10],
        essential_commodities: [1, 12],
        minor_tangible: [1, 24],
        major_tangible: [1, 36]
    };

    $('#loan_type').on('change', function () {
        const type = $(this).val();
        const max = loanLimits[type] || 0;
        const dur = loanDurations[type] || [1, 12];

        if (max) {
            $('#amount').attr('max', max);
            $('#amount_hint').text('Maximum: ₦' + max.toLocaleString());
        }
        if (dur) {
            $('#duration_months').attr('min', dur[0]).attr('max', dur[1]);
            $('#duration_hint').text('Duration: ' + dur[0] + ' - ' + dur[1] + ' months');
        }

        // Essential commodities loan requires no guarantor
        if (type === 'essential_commodities') {
            $('#guarantor_section').hide();
        } else {
            $('#guarantor_section').show();
        }
    });

    // Confirm delete/decline actions
    $(document).on('click', '.btn-confirm', function (e) {
        const msg = $(this).data('confirm') || 'Are you sure?';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });

    // Prevent double-form submission
    $('form').on('submit', function () {
        $(this).find('[type=submit]').prop('disabled', true).text('Processing...');
    });
});
