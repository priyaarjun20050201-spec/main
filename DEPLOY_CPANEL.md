# Deploying Bitlink to cPanel

This guide explains how to deploy the Bitlink Node.js application to a cPanel hosting environment using "Setup Node.js App".

## Prerequisites

*   A cPanel hosting account that supports Node.js (often via CloudLinux and Phusion Passenger).
*   Access to cPanel File Manager or FTP.

## Steps

### 1. Upload Files

1.  Log in to your cPanel account.
2.  Go to **File Manager**.
3.  Create a new folder for your project (e.g., `bitlink`).
4.  Upload the following files/folders to the `bitlink` folder:
    *   `server.js`
    *   `package.json`
    *   `index.html`
    *   `style.css`
    *   `script.js`
    *   `.gitignore` (optional)
    *   *Do NOT upload `node_modules`. You will install dependencies on the server.*

### 2. Create the Node.js Application

1.  In the cPanel main dashboard, look for **Software** section and click on **Setup Node.js App**.
2.  Click **Create Application**.
3.  **Node.js Version:** Select a version compatible with your local environment (e.g., 18.x or 20.x).
4.  **Application Mode:** Select **Production**.
5.  **Application Root:** Enter the path to the folder you created (e.g., `bitlink`).
6.  **Application URL:** Select the domain/subdomain you want to serve the app from.
7.  **Application Startup File:** Enter `server.js`.
8.  Click **Create**.

### 3. Install Dependencies

1.  Once the application is created, the page will reload. Scroll down to the "Detected configuration files" section (or look for a button that says "Run NPM Install").
2.  If you see a button **Run NPM Install**, click it.
3.  Wait for the installation to complete successfully.

### 4. Restart the Application

1.  Click the **Restart** button to start the application.

### 5. Verify

1.  Visit your Application URL. You should see the Bitlink interface.
2.  Try shortening a URL to ensure the API is working.

## Troubleshooting

*   **500 Error / App not starting:** Check the `stderr.log` in your application root folder (`bitlink`).
*   **"Incomplete response received from application":** This usually means the app didn't start correctly or isn't listening on the port cPanel expects. We have updated `server.js` to use `process.env.PORT`, which is required for cPanel/Passenger.
*   **Static files not loading:** Ensure your `index.html` and other assets are in the same directory as `server.js` (as configured in the code), or configure an `.htaccess` file if necessary (though the Express `static` middleware should handle this).
