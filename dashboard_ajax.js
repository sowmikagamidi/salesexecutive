// dashboard_ajax.js - For Dashboard Page
$(document).ready(function() {
    console.log('Dashboard JS loaded');

    // Show/hide custom date fields
    $('#date_filter').on('change', function() {
        if ($(this).val() === 'custom') {
            $('.custom-date-group').addClass('show');
        } else {
            $('.custom-date-group').removeClass('show');
        }
    });

    // Apply filters when Show button is clicked
    $('#showFilters').on('click', function(e) {
        e.preventDefault();
        console.log('Show button clicked');
        showFilters();
    });

    // Also apply on Enter key in inputs
    $('#from_date, #to_date').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            showFilters();
        }
    });

    // Function to apply filters via AJAX
    function showFilters() {
        var formData = $('#filterForm').serialize();
        console.log('Filter data:', formData);
        
        // Show loading indicator
        $('#filterLoading').addClass('show');
        $('#showFilters').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Showing...');
        
        $.ajax({
            url: 'ajax_dashboard.php',
            type: 'GET',
            data: formData,
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                console.log('AJAX Success:', response);
                if (response.success) {
                    updateDashboard(response);
                    if ($('#errorMessage').length > 0) {
                        $('#errorMessage').fadeOut(300);
                    }
                } else {
                    console.error('Error in response:', response);
                    showError('Error showing filters: ' + (response.error || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                console.error('Status:', status);
                console.error('Response:', xhr.responseText);
                showError('Error applying filters. Please try again.');
            },
            complete: function() {
                $('#filterLoading').removeClass('show');
                $('#showFilters').prop('disabled', false).html('<i class="fas fa-filter"></i> Show');
            }
        });
    }

    function showError(message) {
        if ($('#errorMessage').length === 0) {
            $('<div id="errorMessage" style="background: #f8d7da; color: #721c24; padding: 12px 20px; border-radius: 8px; margin: 10px 0; display: none; border: 1px solid #f5c6cb;"></div>').insertAfter('.filter-section');
        }
        $('#errorMessage').html('❌ ' + message).fadeIn(300);
        setTimeout(function() {
            $('#errorMessage').fadeOut(300);
        }, 5000);
    }

    function updateDashboard(response) {
        var stats = response.stats;
        console.log('Updating dashboard with:', stats);
        
        // Update Stats
        $('#totalSales').text('₹ ' + formatNumber(stats.total_sales));
        $('#totalCoupons').text(stats.active_coupons);
        $('#totalOrders').text(stats.total_orders);
        $('#totalCommission').text('₹ ' + formatNumber(stats.total_commission));
        $('#totalStudents').text(stats.total_students);
        
        // Update Summary Stats
        if ($('.summary-stats').length > 0) {
            $('.summary-stats .stat-item .value.blue').text('₹ ' + formatNumber(stats.total_sales));
            $('.summary-stats .stat-item .value.green').text('₹ ' + formatNumber(stats.total_commission));
            $('.summary-stats .stat-item .value.purple').text(stats.total_students);
        }
        
        // Update Coupon Table
        if (response.coupons && response.coupons.length > 0) {
            var couponHtml = '';
            $.each(response.coupons, function(index, coupon) {
                couponHtml += '<tr>' +
                    '<td><span class="coupon-code">' + coupon.coupon_code + '</span></td>' +
                    '<td>' + coupon.issued_on + '</td>' +
                    '<td>' + coupon.expiry_date + '</td>' +
                    '<td><strong>' + coupon.total_used + '</strong></td>' +
                    '<td><strong>' + coupon.total_students + '</strong></td>' +
                    '<td>₹ ' + formatNumber(coupon.sales_amount) + '</td>' +
                    '<td>₹ ' + formatNumber(coupon.commission) + '</td>' +
                '</tr>';
            });
            $('#couponTableBody').html(couponHtml);
        } else {
            $('#couponTableBody').html(
                '<tr><td colspan="7" style="text-align: center; padding: 30px; color: #7f8c8d;">' +
                '<div class="empty-state"><div class="icon">🎫</div><p>No coupons found.</p></div></td></tr>'
            );
        }
        
        // Update Orders Table (Latest 5)
        if (response.orders && response.orders.length > 0) {
            var orderHtml = '';
            $.each(response.orders, function(index, order) {
                orderHtml += '<tr>' +
                    '<td><strong>#' + order.order_id + '</strong></td>' +
                    '<td>' + (order.student_name || 'N/A') + '</td>' +
                    '<td><span class="coupon-code">' + (order.coupon_code || 'N/A') + '</span></td>' +
                    '<td>' + (order.executive_name || 'N/A') + '</td>' +
                    '<td>₹ ' + formatNumber(order.amount) + '</td>' +
                    '<td>' + order.order_date_formatted + '</td>' +
                    '<td><span class="status-badge success">Success</span></td>' +
                '</tr>';
            });
            $('#ordersTableBody').html(orderHtml);
        } else {
            $('#ordersTableBody').html(
                '<tr><td colspan="7" style="text-align: center; padding: 30px; color: #7f8c8d;">' +
                '<div class="empty-state"><div class="icon">📋</div><p>No orders found.</p></div></td></tr>'
            );
        }
        
        // Update Chart
        updateChart(response.chart_data);
        
        // Update Filter Info
        var filterHtml = '<strong>' + response.date_display + '</strong>';
        if (response.coupon_display !== 'All Coupons') {
            filterHtml += ' | Coupon: <strong>' + response.coupon_display + '</strong>';
        }
        $('#filterInfo').html(filterHtml);
        
        // Update View All Links
        updateViewAllLinks(response);
        console.log('Dashboard updated successfully');
    }

    function formatNumber(num) {
        return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }
    
    function updateViewAllLinks(response) {
        var baseUrl = 'view_all_executive.php';
        var params = [];
        params.push('executive=' + response.executive_id);
        params.push('date_filter=' + $('#date_filter').val());
        
        var couponVal = $('#coupon_filter').val();
        if (couponVal != 'all') {
            params.push('coupon_filter=' + couponVal);
        }
        
        var fromDate = $('#from_date').val();
        var toDate = $('#to_date').val();
        if (fromDate) {
            params.push('from_date=' + fromDate);
        }
        if (toDate) {
            params.push('to_date=' + toDate);
        }
        
        var queryString = '?' + params.join('&');
        
        $('.section:has(.section-title:contains("Orders")) .btn-primary').attr('href', baseUrl + '?type=orders' + queryString);
        $('.section:has(.section-title:contains("Coupon Performance")) .btn-primary').attr('href', baseUrl + '?type=coupons' + queryString);
    }
    
    function updateChart(chartData) {
        if (!chartData) {
            chartData = [];
        }
        
        var labels = chartData.length > 0 ? chartData.map(item => item.date_label) : ['No Data'];
        var sales = chartData.length > 0 ? chartData.map(item => parseFloat(item.sales).toFixed(2)) : [0];
        var commissions = chartData.length > 0 ? chartData.map(item => parseFloat(item.commission).toFixed(2)) : [0];
        
        if (window.salesChartInstance) {
            window.salesChartInstance.data.labels = labels;
            window.salesChartInstance.data.datasets[0].data = sales;
            window.salesChartInstance.data.datasets[1].data = commissions;
            window.salesChartInstance.update();
        }
    }
});