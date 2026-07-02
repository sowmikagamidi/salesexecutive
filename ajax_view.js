// ajax_dashboard.js - For View All Executive Page
$(document).ready(function() {
    console.log('View All JS loaded');

    // =============================================
    // VIEW ALL EXECUTIVE PAGE (view_all_executive.php)
    // =============================================
    
    // Open panel
    $('#openPanel').on('click', function() {
        $('#filterPanel').addClass('open');
        $('#panelOverlay').addClass('show');
        $('body').addClass('panel-open');
        $(this).addClass('active').html('<i class="fas fa-times"></i> Close');
    });

    // Close panel
    function closePanel() {
        $('#filterPanel').removeClass('open');
        $('#panelOverlay').removeClass('show');
        $('body').removeClass('panel-open');
        $('#openPanel').removeClass('active').html('<i class="fas fa-filter"></i> Filters');
    }

    $('#closePanel, #panelOverlay').on('click', function() {
        closePanel();
    });

    // Show/hide custom date fields for view_all
    $('#filter_date_filter').on('change', function() {
        if ($(this).val() === 'custom') {
            $('#customDateGroup').addClass('show');
        } else {
            $('#customDateGroup').removeClass('show');
        }
    });

    // Coupon search for view_all
    $('#couponSearch').on('keyup', function() {
        var search = $(this).val().toLowerCase().trim();
        $('#couponList .coupon-item').each(function() {
            var text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(search) > -1);
        });
        if ($(this).val().length > 0) {
            $('#couponList').addClass('show');
        }
    });

    $('#couponSearch').on('focus', function() {
        $('#couponList .coupon-item').show();
        $('#couponList').addClass('show');
    });

    $('#couponSearch').on('blur', function() {
        setTimeout(function() {
            $('#couponList').removeClass('show');
        }, 300);
    });

    // Select coupon from list for view_all
    $('#couponList .coupon-item').on('click', function() {
        var value = $(this).data('value');
        var text = $(this).text();
        
        $('#couponList .coupon-item').removeClass('selected');
        $(this).addClass('selected');
        
        $('#filter_coupon_filter').val(value);
        $('#couponSearch').val(text);
        if (value == 'all') {
            $('#selectedCouponDisplay').html('');
        } else {
            $('#selectedCouponDisplay').html('<span class="selected-coupon">' + text + '</span>');
        }
        $('#couponList').removeClass('show');
    });

    // Apply filters for VIEW ALL - Reload page with new filters
    $('#applyFilters').on('click', function(e) {
        e.preventDefault();
        console.log('View All Apply button clicked');
        
        $('#loadingOverlay').addClass('show');
        $('#applyFilters').prop('disabled', true).text('Applying...');
        
        // Build URL with all filter parameters
        var url = 'view_all_executive.php?';
        url += 'type=' + $('#filterForm input[name="type"]').val();
        url += '&executive=' + $('#filterForm input[name="executive"]').val();
        url += '&date_filter=' + $('#filter_date_filter').val();
        url += '&coupon_filter=' + $('#filter_coupon_filter').val();
        url += '&page=1';
        
        if ($('#filter_from_date').val()) {
            url += '&from_date=' + $('#filter_from_date').val();
        }
        if ($('#filter_to_date').val()) {
            url += '&to_date=' + $('#filter_to_date').val();
        }
        
        // Redirect to reload page with filters
        window.location.href = url;
    });

    // Reset filters for view_all
    $('#resetFilters').on('click', function(e) {
        e.preventDefault();
        var type = $('#filterForm input[name="type"]').val();
        var executive = $('#filterForm input[name="executive"]').val();
        window.location.href = 'view_all_executive.php?type=' + type + '&executive=' + executive;
    });
});