// emailTemplates.js
const generateEmailContent = (feed, percentage) => {
  const {
    feedid,
    feedname,
    customerEmail,
    publisherEmail,
    budget,
    customerID,
    customerName,
    publisherName,
  } = feed;

  let greeting = "Hello";
  let recipientName = customerName || "Customer";
  if (percentage === "75%" && publisherName) {
    greeting = `Hello ${publisherName}`;
    recipientName = publisherName;
  } else if (percentage === "75%") {
    greeting = `Hello ${customerName}`;
    recipientName = customerName;
  } else if (percentage === "90%" && publisherName) {
    greeting = `Hello ${publisherName}`;
    recipientName = publisherName;
  }

  let message;
  if (percentage === "75%") {
    message = `
        <p>We wanted to notify you that your campaign <strong>${feedname} (${feedid})</strong> is currently at <strong>75%</strong> of the allocated monthly budget.</p>
        <p style="font-size: 18px; font-weight: bold; color: #2a3d44;">Current Budget: $${budget}</p>
        <p>To ensure that your campaign continues running smoothly this month, please consider increasing your budget.</p>
      `;
  } else if (percentage === "90%") {
    message = `
        <p>Your campaign <strong>${feedname} (${feedid})</strong> has reached <strong>90%</strong> of the allocated monthly budget.</p>
        <p style="font-size: 18px; font-weight: bold; color: #2a3d44;">Current Budget: $${budget}</p>
        <p>If you want your campaign to continue, please adjust your budget as the campaign will stop automatically at 95%.</p>
      `;
  }

  // Final HTML structure with attractive design
  return `
      <!DOCTYPE html>
      <html lang="en">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Appljack Budget Alert</title>
          <style>
            body {
              font-family: 'Arial', sans-serif;
              background-color: #f4f7f9;
              margin: 0;
              padding: 0;
            }
            .email-container {
              max-width: 600px;
              margin: 20px auto;
              background-color: #ffffff;
              border-radius: 8px;
              overflow: hidden;
              box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            }
            .email-header {
              background-color: #2a3d44;
              padding: 34px 20px;
              text-align: center;
            }
            .email-header img {
              max-width: 206px;
            }
            .email-body {
              padding: 40px 30px;
              color: #333333;
            }
            h1 {
              color: #2a3d44;
              font-size: 24px;
              margin-bottom: 20px;
            }
            p {
              margin-bottom: 15px;
              line-height: 1.6;
            }
            .cta-button {
              display: inline-block;
              padding: 12px 24px;
              background-color: #7aaa4b;
              color: #ffffff;
              text-decoration: none;
              border-radius: 5px;
              font-weight: bold;
              margin-top: 20px;
              transition: background-color 0.3s ease;
            }
            .cta-button:hover {
              background-color: #4e6766;
            }
            .email-footer {
              background-color: #ecf0f1;
              text-align: center;
              padding: 20px;
              font-size: 14px;
              color: #7f8c8d;
            }
            .email-footer a {
              color: #7aaa4b;
              text-decoration: none;
            }
          </style>
        </head>
        <body>
          <div class="email-container">
            <div class="email-header">
              <img src="https://appljack.com/admin/images/white-logo.png" alt="Appljack Logo">
            </div>
            <div class="email-body" style="background: #ecf0f11f;">
              <h1 style="margin-top: 0px">${percentage} Budget Alert</h1>
              <p>${greeting},</p>
              ${message}
              <a href="https://www.appljack.com/admin/editfeed.php?feedid=${feedid}&_t=m&_c=${customerID}" class="cta-button" style="color: white!important">Adjust Your Budget</a> 
            </div>
            <div class="email-footer">
              <p>Thank you for using Appljack!</p>
              <p>For any questions, feel free to <a href="https://appljack.com/contact/" style="color: #4e6766">contact us</a>.</p>
              <!-- <p>For any questions, feel free to <a href="mailto:support@appljack.com" style="color: #4e6766">contact us</a>.</p> -->
            </div>
          </div>
        </body>
      </html>
    `;
};

// Export the function for use in other files
if (typeof module !== "undefined" && module.exports) {
  module.exports = { generateEmailContent };
}
