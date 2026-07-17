    <!-- Fonts -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- icons --}}

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" />


    <script src="{{ asset('js/jquery.min.js') }}"></script>


    <script>
        function showLoader() {
            $("#loader").removeClass("hidden");
            $("button").attr("disabled", true);
        }

        function hideLoader() {
            $("#loader").addClass("hidden");
            $("button").removeAttr("disabled");
        }
    </script>
