<footer>
    <p>&copy; 2024 Appljack | Powered by Job Hub Central</p>
    <!-- Add more footer elements here -->
</footer>
<script src="https://code.jquery.com/jquery-3.6.1.js" integrity="sha256-3zlB5s2uwoUzrXK3BT7AX3FyvojsraNFxCc2vC/7pNI=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" integrity="sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy" crossorigin="anonymous"></script>
<script src="js/jquery.min.js"></script>
<script src="js/popper.js"></script>
<script src="js/bootstrap.min.js"></script>
<script>
    toastr.options = {
        "closeButton": true,
        // "debug": false,
        "newestOnTop": false,
        "progressBar": true,
        // "positionClass": "toast-top-right",
        "positionClass": "toast-bottom-right",
        // "preventDuplicates": false,
        // "onclick": null,
        // "showDuration": "300",
        // "hideDuration": "1000",
        "timeOut": "6000",
        // "extendedTimeOut": "1000",
        "showEasing": "swing",
        "hideEasing": "linear",
        "showMethod": "fadeIn",
        "hideMethod": "fadeOut"
    };

    (function($) {
        "use strict";

        // Function to set full height for the sidebar
        var fullHeight = function() {
            $('.js-fullheight').css('height', $(window).height());
            $(window).resize(function() {
                $('.js-fullheight').css('height', $(window).height());
            });
        };

        fullHeight();

        // Check if the sidebar was collapsed in localStorage
        if (localStorage.getItem('sidebarState') === 'collapsed') {
            $('#sidebar').addClass('active'); // Ensure the state is collapsed
            $('#content').addClass('active'); // Ensure the state is collapsed
        } else {
            $('#sidebar').removeClass('active'); // Ensure the state is collapsed
            $('#content').removeClass('active'); // Ensure the state is collapsed
        }

        // Toggle sidebar collapse on button click
        $('#sidebarCollapse').on('click', function() {
            $('#sidebar').toggleClass('active');
            $('#content').toggleClass('active');

            // Save the sidebar state in localStorage
            if ($('#sidebar').hasClass('active')) {
                localStorage.setItem('sidebarState', 'collapsed');
            } else {
                localStorage.setItem('sidebarState', 'expanded');
            }
        });
    })(jQuery);
</script>
</body>

</html>