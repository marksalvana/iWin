(function($){
    $(document).ready(function(){
        function applyFilters() {
            var visibleCount = 0;

            $('.forminator-image-item-wrapper').each(function(){
                var $item = $(this);
                var isMatch = true;

                $('.iwin-gallery-display-filter').each(function(){
                    var filterKey = $(this).data('filter');
                    var filterValue = $(this).val().toLowerCase();

                    if (filterValue) {
                        var itemValue = String($item.data(filterKey)).toLowerCase();

                        if (itemValue.indexOf(filterValue) === -1) {
                            isMatch = false;
                            return false; // break loop
                        }
                    }
                });

                if (isMatch) {
                    $item.show();
                    visibleCount++;
                } else {
                    $item.hide();
                }
            });

            // No results message
            if (visibleCount === 0) {
                $('.forminator-lightbox-grid').after('<p class="empty-filter-result">No result found</p>');
            }
        }

        $('.iwin-gallery-display-filter').on('change', function(){
            $('.empty-filter-result').remove()
            applyFilters();
        });

    });
})(jQuery);