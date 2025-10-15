jQuery(document).ready(function($) {
    
    // Test Connection
    $('#sc-test-connection').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Testing...');
        
        $.post(sc_podcasts.ajax_url, {
            action: 'sc_podcasts_test_connection',
            nonce: sc_podcasts.nonce
        }, function(response) {
            if (response.success) {
                alert('Connection successful!');
            } else {
                alert('Connection failed. Check your API token.');
            }
        }).always(function() {
            $button.prop('disabled', false).text('Test Connection');
        });
    });
    
    // Manual Sync
    $('#sc-manual-sync').on('click', function() {
        if (!confirm('This will sync all episodes from Supporting Cast. Continue?')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true).text('Syncing...');
        
        $.post(sc_podcasts.ajax_url, {
            action: 'sc_podcasts_manual_sync',
            nonce: sc_podcasts.nonce
        }, function(response) {
            if (response.success) {
                alert('Sync completed!\n' + 
                      'Synced: ' + response.data.stats.synced + '\n' +
                      'Failed: ' + response.data.stats.failed + '\n' +
                      'Total: ' + response.data.stats.total);
                location.reload();
            } else {
                alert('Sync failed: ' + response.data.message);
            }
        }).always(function() {
            $button.prop('disabled', false).text('Run Manual Sync');
        });
    });
    
    // Analyze Episodes
    $('#sc-analyze-episodes').on('click', function() {
        var $button = $(this);
        var $results = $('#sc-analysis-results');
        
        $button.prop('disabled', true).text('Analyzing...');
        $results.html('');
        
        $.post(sc_podcasts.ajax_url, {
            action: 'sc_analyze_episodes',
            nonce: sc_podcasts.nonce
        }, function(response) {
            if (response.success) {
                var data = response.data;
                var html = '<div class="sc-podcasts-migration-results success">';
                html += '<h3>Analysis Complete</h3>';
                html += '<p><strong>Increment Episodes:</strong> ' + data.increment_total + '</p>';
                html += '<p><strong>SC Episodes:</strong> ' + data.sc_total + '</p>';
                html += '<p><strong>Matched:</strong> ' + data.matched + '</p>';
                html += '<p><strong>Unmatched:</strong> ' + data.unmatched + '</p>';
                
                if (data.unmatched_episodes.length > 0) {
                    html += '<h4>Unmatched Episodes:</h4>';
                    html += '<ul>';
                    data.unmatched_episodes.slice(0, 10).forEach(function(ep) {
                        html += '<li>' + ep.title + ' (ID: ' + ep.id + ', Date: ' + ep.date + ')</li>';
                    });
                    if (data.unmatched_episodes.length > 10) {
                        html += '<li>... and ' + (data.unmatched_episodes.length - 10) + ' more</li>';
                    }
                    html += '</ul>';
                }
                
                html += '</div>';
                $results.html(html);
            } else {
                $results.html('<div class="sc-podcasts-migration-results error">Analysis failed</div>');
            }
        }).always(function() {
            $button.prop('disabled', false).text('Analyze Episodes');
        });
    });
    
    // Copy Episodes
    $('#sc-copy-episodes').on('click', function() {
        if (!confirm('This will copy all Increment episodes to SC episodes. Continue?')) {
            return;
        }
        
        var $button = $(this);
        var $results = $('#sc-copy-results');
        
        $button.prop('disabled', true).text('Copying...');
        $results.html('');
        
        $.post(sc_podcasts.ajax_url, {
            action: 'sc_copy_episodes',
            nonce: sc_podcasts.nonce
        }, function(response) {
            if (response.success) {
                var data = response.data;
                var html = '<div class="sc-podcasts-migration-results success">';
                html += '<h3>Copy Complete</h3>';
                html += '<p><strong>Copied:</strong> ' + data.copied + '</p>';
                html += '<p><strong>Skipped (already migrated):</strong> ' + data.skipped + '</p>';
                html += '<p><strong>Failed:</strong> ' + data.failed + '</p>';
                html += '<p><strong>Total:</strong> ' + data.total + '</p>';
                html += '</div>';
                $results.html(html);
            } else {
                $results.html('<div class="sc-podcasts-migration-results error">Copy failed</div>');
            }
        }).always(function() {
            $button.prop('disabled', false).text('Copy Episodes');
        });
    });
    
    // Feed Mapping Save
    $('select[name^="feed_mapping"]').on('change', function() {
        var $select = $(this);
        var feedId = $select.attr('name').match(/\[(\d+)\]/)[1];
        var termId = $select.val();
        
        $.post(sc_podcasts.ajax_url, {
            action: 'sc_podcasts_save_feed_mapping',
            nonce: sc_podcasts.nonce,
            feed_id: feedId,
            term_id: termId
        }, function(response) {
            if (response.success) {
                $select.css('background-color', '#d4edda');
                setTimeout(function() {
                    $select.css('background-color', '');
                }, 2000);
            }
        });
    });
});
