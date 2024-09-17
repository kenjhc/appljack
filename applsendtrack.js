// applsendtrack.js
function sendTrackingData() {
    // Retrieve data from session storage
    const acid = sessionStorage.getItem('acid');
    const afid = sessionStorage.getItem('afid');
    const ajid = sessionStorage.getItem('ajid');

    // Ensure data exists before sending
    if (acid && afid && ajid) {
        // Construct the tracking URL with query parameters
        const trackingURL = `https://appljack.com/public/track.png?c=${acid}&f=${afid}&j=${ajid}`;

        // Create a new image element for the tracking pixel
        const img = new Image();
        img.onload = function() {
            console.log('Tracking pixel sent successfully.');
        };
        img.onerror = function() {
            console.error('Error sending tracking pixel.');
        };
        img.src = trackingURL;
        img.style.display = 'none'; // Hide the image

        // Append the image to the body to trigger the request
        document.body.appendChild(img);
    } else {
        console.log('No tracking data found in session storage.');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    sendTrackingData();
});
