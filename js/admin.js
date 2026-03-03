jQuery(document).ready(function($) {
    $('#vt-date-picker').datepicker({
        dateFormat: 'yy-mm-dd',
        maxDate: 0
    });
    
    $('#vt-filter-btn').on('click', function() {
        var selectedDate = $('#vt-date-picker').val();
        
        $.ajax({
            url: versionTrackerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'version_tracker_get_versions',
                date: selectedDate
            },
            success: function(response) {
                var data = JSON.parse(response);
                $('#vt-versions-container').html(data.html);
            },
            error: function() {
                alert('Error loading versions');
            }
        });
    });
});