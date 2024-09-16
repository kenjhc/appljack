document.addEventListener('DOMContentLoaded', function() {
    function getQueryParam(name) {
        name = name.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]");
        var regex = new RegExp("[\\?&]" + name + "=([^&#]*)"),
            results = regex.exec(location.search);
        return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
    }

    var acid = getQueryParam('c');
    var afid = getQueryParam('f');
    var ajid = getQueryParam('j');

    sessionStorage.setItem('acid', acid);
    sessionStorage.setItem('afid', afid);
    sessionStorage.setItem('ajid', ajid);
});
