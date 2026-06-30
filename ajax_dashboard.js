// ajax_dashboard.js
$(document).ready(function() {
    // Show/hide custom date fields
    $('#date_filter').on('change', function() {
        if ($(this).val() === 'custom') {
            $('.custom-date-group').addClass('show');
        } else {
            $('.custom-date-group').removeClass('show');
        }
        applyFilters();
    });

    // Auto-apply when coupon filter changes
    $('#coupon_filter').on('change', function() {
        applyFilters();
    });

    // Auto-apply when custom date inputs change
    $('#from_date, #to_date').on('change', function() {
        if ($('#date_filter').val() === 'custom') {
            applyFilters();
        }
    });

    // Function to apply filters via AJAX
    function applyFilters() {
        var formData = $('#filterForm').serialize();
        
        // Show loading indicator
        $('#filterLoading').addClass('show');
        
        $.ajax({
            url: 'ajax_dashboard.php',
            type: 'GET',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    updateDashboard(response);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
            },
            complete: function() {
                $('#filterLoading').removeClass('show');
            }
        });
    }

    // Function to update dashboard with response data
    function updateDashboard(response) {
        var stats = response.stats;
        
        // Update Stats
        $('#totalSales').text('₹ ' + formatNumber(stats.total_sales));
        $('#totalCoupons').text(stats.active_coupons);
        $('#totalOrders').text(stats.total_orders);
        $('#totalCommission').text('₹ ' + formatNumber(stats.total_commission));
        $('#totalStudents').text(stats.total_students);
        
        // Update Summary Stats
        $('.summary-stats .stat-item .value.blue').text('₹ ' + formatNumber(stats.total_sales));
        $('.summary-stats .stat-item .value.green').text('₹ ' + formatNumber(stats.total_commission));
        $('.summary-stats .stat-item .value.purple').text(stats.total_students);
        
        // Update Coupon Table
        if (response.coupons.length > 0) {
            var couponHtml = '';
            $.each(response.coupons, function(index, coupon) {
                var usageRate = Math.min(coupon.usage_rate, 100);
                var barColor = usageRate > 70 ? 'green' : (usageRate > 40 ? 'orange' : 'red');
                couponHtml += '<tr>' +
                    '<td><span class="coupon-code">' + coupon.coupon_code + '</span></td>' +
                    '<td>' + coupon.issued_on + '</td>' +
                    '<td>' + coupon.expiry_date + '</td>' +
                    '<td><strong>' + coupon.total_used + '</strong></td>' +
                    '<td><strong>' + coupon.total_students + '</strong></td>' +
                    '<td>₹ ' + formatNumber(coupon.sales_amount) + '</td>' +
                    '<td>₹ ' + formatNumber(coupon.commission) + '</td>' +
                    '<td>' +
                        '<div class="progress-bar"><div class="fill ' + barColor + '" style="width: ' + usageRate + '%;"></div></div>' +
                        '<span style="margin-left: 8px; font-size: 12px; font-weight: 600;">' + usageRate + '%</span>' +
                    '</td>' +
                '</tr>';
            });
            $('#couponTableBody').html(couponHtml);
        } else {
            $('#couponTableBody').html(
                '<tr><td colspan="8" style="text-align: center; padding: 30px; color: #7f8c8d;">' +
                '<div class="empty-state"><div class="icon">🎫</div><p>No coupons found.</p></div></td></tr>'
            );
        }
        
        // Update Orders Table
        if (response.orders.length > 0) {
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
        var baseUrl = 'view_all_executive.php';
        var params = [];
        params.push('executive=<?php echo $executive_id; ?>');
        if (response.date_display) {
            params.push('date_filter=' + $('#date_filter').val());
        }
        if (response.coupon_id != 'all') {
            params.push('coupon_filter=' + response.coupon_id);
        }
        if ($('#from_date').val()) {
            params.push('from_date=' + $('#from_date').val());
        }
        if ($('#to_date').val()) {
            params.push('to_date=' + $('#to_date').val());
        }
        var queryString = params.length > 0 ? '?' + params.join('&') : '';
        
        $('.section:has(.section-title:contains("Orders")) .btn-secondary').attr('href', baseUrl + '?type=orders' + queryString);
        $('.section:has(.section-title:contains("Coupon Performance")) .btn-secondary').attr('href', baseUrl + '?type=coupons' + queryString);
    }

    function formatNumber(num) {
        return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }
    
    function updateChart(chartData) {
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