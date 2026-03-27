<?php
/**
* Plugin Name: Event Cover Generator - ATTENDEE FIXED
* Description: Fixed attendee mode detection and UI switching
* Version: 3.2.1
* Author: MGX Team
*/

// Prevent direct access
if (!defined('ABSPATH')) {
exit;
}

/**
* Plugin activation hook - setup cron jobs
*/
function ecg_final_activate() {
// Schedule daily cleanup of expired data
if (!wp_next_scheduled('ecg_cleanup_expired_data')) {
wp_schedule_event(time(), 'daily', 'ecg_cleanup_expired_data');
}

// Schedule hourly expiration notifications
if (!wp_next_scheduled('ecg_send_expiration_notifications')) {
wp_schedule_event(time(), 'hourly', 'ecg_send_expiration_notifications');
}
}
register_activation_hook(__FILE__, 'ecg_final_activate');

/**
* Plugin deactivation hook - clear cron jobs
*/
function ecg_final_deactivate() {
wp_clear_scheduled_hook('ecg_cleanup_expired_data');
wp_clear_scheduled_hook('ecg_send_expiration_notifications');
}
register_deactivation_hook(__FILE__, 'ecg_final_deactivate');

/**
* Cleanup expired transient data
*/
function ecg_cleanup_expired_data() {
global $wpdb;

// Get all ECG transients
$transients = $wpdb->get_results(
"SELECT option_name FROM {$wpdb->options}
WHERE option_name LIKE '_transient_ecg_share_%'
OR option_name LIKE '_transient_timeout_ecg_share_%'"
);

$cleaned = 0;
foreach ($transients as $transient) {
$key = str_replace(['_transient_timeout_', '_transient_'], '', $transient->option_name);

// Check if transient has expired
if (strpos($transient->option_name, '_transient_timeout_') === 0) {
$timeout = get_option($transient->option_name);
if ($timeout && $timeout < time()) {
delete_transient(str_replace('_transient_timeout_', '', $transient->option_name));
$cleaned++;
}
}
}

error_log("ECG: Cleaned up {$cleaned} expired transients");
}
add_action('ecg_cleanup_expired_data', 'ecg_cleanup_expired_data');

/**
* Send expiration notifications
*/
function ecg_send_expiration_notifications() {
global $wpdb;

// Get all active ECG transients
$transients = $wpdb->get_results(
"SELECT option_name, option_value FROM {$wpdb->options}
WHERE option_name LIKE '_transient_ecg_share_%'"
);

foreach ($transients as $transient) {
$data = maybe_unserialize($transient->option_value);
if (!is_array($data) || !isset($data['eventDetails'])) continue;

$token = str_replace('_transient_ecg_share_', '', $transient->option_name);
$eventDetails = $data['eventDetails'];
$expires = isset($data['expires']) ? $data['expires'] : 0;

if (!$expires || !isset($eventDetails['name'])) continue;

$hoursUntilExpiry = ($expires - time()) / 3600;

// Send notification if expiring in 24 hours and not already notified
if ($hoursUntilExpiry <= 24 && $hoursUntilExpiry > 0) {
$notified_key = 'ecg_notified_' . $token;
if (!get_transient($notified_key)) {
ecg_send_expiration_email($eventDetails, $expires, $token);
set_transient($notified_key, true, DAY_IN_SECONDS);
}
}
}
}
add_action('ecg_send_expiration_notifications', 'ecg_send_expiration_notifications');

/**
* Send expiration email notification
*/
function ecg_send_expiration_email($eventDetails, $expires, $token) {
$admin_email = get_option('admin_email');
$site_name = get_bloginfo('name');
$event_name = sanitize_text_field($eventDetails['name']);
$expiry_date = date('F j, Y \a\t g:i A', $expires);

$subject = "Event Cover Generator - Link Expiring Soon for {$event_name}";

$message = "
<html>
<body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
<div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
<h2 style='color: #10b981;'>⏰ Share Link Expiring Soon</h2>

<p>Hello,</p>

<p>This is a reminder that your Event Cover Generator share link for <strong>" . esc_html($event_name) . "</strong> will expire soon.</p>

<div style='background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 15px; margin: 20px 0;'>
<h3 style='margin: 0 0 10px 0; color: #059669;'>📅 Event Details</h3>
<p style='margin: 5px 0;'><strong>Event:</strong> " . esc_html($event_name) . "</p>
<p style='margin: 5px 0;'><strong>Link Expires:</strong> {$expiry_date}</p>
<p style='margin: 5px 0;'><strong>Token:</strong> " . esc_html($token) . "</p>
</div>

<p>After the expiration time, attendees will no longer be able to access the link to upload their photos and download personalized covers.</p>

<p>If you need to extend access, you can create a new event cover with a later end date.</p>

<hr style='border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;'>

<p style='font-size: 14px; color: #6b7280;'>
This notification was sent by the Event Cover Generator plugin on " . esc_html($site_name) . ".<br>
You're receiving this because you created an event cover that's about to expire.
</p>
</div>
</body>
</html>
";

$headers = array(
'Content-Type: text/html; charset=UTF-8',
'From: ' . $site_name . ' <' . $admin_email . '>'
);

wp_mail($admin_email, $subject, $message, $headers);
error_log("ECG: Expiration notification sent for event: {$event_name}");
}

/**
* Enqueue scripts and styles for the event cover generator
*/
function ecg_final_enqueue_scripts() {
global $post;
// Determine if scripts should be enqueued on this page.
// Enqueue on attendee pages with valid tokens or pages containing the shortcode.
$should_enqueue = false;
// Attendee pages: ecg_token parameter must be exactly 20 alphanumeric characters
if (!is_admin() && isset($_GET['ecg_token']) && preg_match('/^[A-Za-z0-9]{20}$/', $_GET['ecg_token'])) {
$should_enqueue = true;
}
// Creator pages: page contains the shortcode
if (!$should_enqueue && is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'event_cover_generator_final')) {
$should_enqueue = true;
}
if (!$should_enqueue) {
return;
}

// Register script handle
wp_register_script('ecg-final-js', '', array(), '3.2.1', true);
// Localize AJAX variables
wp_localize_script('ecg-final-js', 'ecgAjax', array(
'ajax_url' => admin_url('admin-ajax.php'),
// Generate a nonce tied to the specific AJAX action. This will be verified
// in the ecg_save_share_final handler below.
'nonce' => wp_create_nonce('ecg_save_share_final')
));

// FIXED JAVASCRIPT - ATTENDEE MODE DETECTION AND UI SWITCHING

wp_add_inline_script('ecg-final-js', <<<'JS'
(function() {
"use strict";

// Constants
const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
const ALLOWED_TYPES = ["image/jpeg", "image/jpg", "image/png", "image/webp"];
const DIMENSIONS = {
square: { width: 1080, height: 1080, name: 'Square' },
portrait: { width: 1080, height: 1350, name: 'Portrait' },
landscape: { width: 1920, height: 1080, name: 'Landscape' }
};

// Global state
let appState = {
currentStep: 1,
attendeeMode: false,
backgroundImage: null,
attendeeImage: null,
eventDetails: { name: '', endDate: '' },
dimensions: DIMENSIONS.square,
bgFitMode: 'fill',
placeholder: { shape: 'circle', size: 50, x: 0.5, y: 0.5 },
history: [],
historyIndex: -1,
isDragging: false,
dragOffset: { x: 0, y: 0 },
isRendering: false
};

let canvas, ctx;

// Debug logging
function debugLog(message, data = null) {
console.log('[ECG ATTENDEE FIXED]', message, data || '');
}

// Utility functions
function showToast(message, type = 'success') {
const toast = document.createElement('div');
toast.className = `ecg-toast ecg-toast-${type}`;
toast.textContent = message;
document.body.appendChild(toast);

setTimeout(() => toast.classList.add('show'), 100);
setTimeout(() => {
toast.classList.remove('show');
setTimeout(() => {
if (document.body.contains(toast)) {
document.body.removeChild(toast);
}
}, 300);
}, type === 'error' ? 5000 : 3000);
}

function validateFile(file) {
if (!ALLOWED_TYPES.includes(file.type)) {
throw new Error("Please upload a valid image file (JPG, PNG, WebP)");
}
if (file.size > MAX_FILE_SIZE) {
throw new Error("File size exceeds 10MB limit");
}
}

/**
* Fetch shared event data from the server when inline data is
* unavailable. This function calls the wp-admin AJAX endpoint
* and returns the parsed JSON data or null on failure. It also
* surfaces errors to the user via toasts and logs to the console.
*
* @param {string} token The 20 character share token
* @returns {Object|null}
*/
async function fetchSharedData(token) {
try {
const response = await fetch(ecgAjax.ajax_url, {
method: 'POST',
headers: {
'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
},
body: new URLSearchParams({
action: 'ecg_get_share_data',
token: token
})
});
const result = await response.json();
if (result.success) {
debugLog('Fetched shared data from server', result.data);
return result.data;
} else {
debugLog('Failed to fetch shared data', result.data);
showToast(result.data || 'Invalid or expired link. Please check the URL.', 'error');
return null;
}
} catch (error) {
debugLog('Error fetching shared data', error);
showToast('An error occurred while loading the shared data.', 'error');
return null;
}
}

// COMPLETELY REWRITTEN IMAGE PROCESSING - SYNCHRONOUS APPROACH
function processImageSync(file) {
return new Promise((resolve, reject) => {
const reader = new FileReader();
reader.onload = (e) => {
const img = new Image();
img.crossOrigin = "anonymous";
img.onload = () => {
try {
const tempCanvas = document.createElement("canvas");
const tempCtx = tempCanvas.getContext("2d");
const maxDim = 2048;
let { width, height } = img;

if (width > maxDim || height > maxDim) {
const ratio = Math.min(maxDim / width, maxDim / height);
width *= ratio;
height *= ratio;
}

tempCanvas.width = width;
tempCanvas.height = height;
tempCtx.drawImage(img, 0, 0, width, height);

const dataUrl = tempCanvas.toDataURL("image/jpeg", 0.9);
debugLog('Image processed successfully', { width, height, dataUrlLength: dataUrl.length });
resolve(dataUrl);
} catch (error) {
debugLog('Image processing error', error);
reject(new Error("Failed to process image"));
}
};
img.onerror = () => {
debugLog('Image load error');
reject(new Error("Failed to load image"));
};
img.src = e.target.result;
};
reader.onerror = () => {
debugLog('File read error');
reject(new Error("Failed to read file"));
};
reader.readAsDataURL(file);
});
}

function getPlaceholderBounds() {
if (!canvas) return { x: 0, y: 0, width: 0, height: 0 };
const { width, height } = canvas;
const placeholderWidth = (appState.placeholder.size / 100) * width;
let ratio = 1;
if (appState.placeholder.shape === "portrait") ratio = 1.4;
const placeholderHeight = placeholderWidth * ratio;

const centerX = Math.max(
placeholderWidth / 2,
Math.min(width - placeholderWidth / 2, appState.placeholder.x * width)
);
const centerY = Math.max(
placeholderHeight / 2,
Math.min(height - placeholderHeight / 2, appState.placeholder.y * height)
);

return {
x: centerX - placeholderWidth / 2,
y: centerY - placeholderHeight / 2,
width: placeholderWidth,
height: placeholderHeight
};
}

function isPointInPlaceholder(x, y) {
const bounds = getPlaceholderBounds();

if (appState.placeholder.shape === "circle") {
const cx = bounds.x + bounds.width / 2;
const cy = bounds.y + bounds.height / 2;
const radius = Math.min(bounds.width, bounds.height) / 2;
const dx = x - cx;
const dy = y - cy;
return (dx * dx + dy * dy) <= (radius * radius);
} else {
return x >= bounds.x && x <= bounds.x + bounds.width &&
y >= bounds.y && y <= bounds.y + bounds.height;
}
}

// COMPLETELY REWRITTEN CANVAS RENDERING - GUARANTEED TO WORK
function renderCanvas() {
if (!canvas || !ctx || appState.isRendering) {
debugLog('Canvas not available or already rendering');
return;
}

appState.isRendering = true;

debugLog('Starting canvas render', {
canvasWidth: canvas.width,
canvasHeight: canvas.height,
hasBackground: !!appState.backgroundImage,
hasAttendeeImage: !!appState.attendeeImage
});

try {
// Set canvas dimensions
canvas.width = appState.dimensions.width;
canvas.height = appState.dimensions.height;

// Clear canvas with white background
ctx.clearRect(0, 0, canvas.width, canvas.height);
ctx.fillStyle = "#ffffff";
ctx.fillRect(0, 0, canvas.width, canvas.height);

// Draw background image if available
if (appState.backgroundImage) {
drawBackgroundImage();
} else {
drawPlaceholder();
}
} catch (error) {
debugLog('Canvas render error', error);
showToast('Canvas rendering error', 'error');
} finally {
appState.isRendering = false;
}
}

function drawBackgroundImage() {
const img = new Image();
img.crossOrigin = "anonymous";

img.onload = () => {
try {
debugLog('Background image loaded for rendering', {
width: img.width,
height: img.height
});

let scale;
if (appState.bgFitMode === 'fill') {
scale = Math.max(canvas.width / img.width, canvas.height / img.height);
} else {
scale = Math.min(canvas.width / img.width, canvas.height / img.height);
}

const imgWidth = img.width * scale;
const imgHeight = img.height * scale;
const x = (canvas.width - imgWidth) / 2;
const y = (canvas.height - imgHeight) / 2;

ctx.drawImage(img, x, y, imgWidth, imgHeight);
debugLog('Background image drawn successfully');

// Draw placeholder after background
drawPlaceholder();

} catch (error) {
debugLog('Background image draw error', error);
drawPlaceholder();
}
};

img.onerror = () => {
debugLog('Background image failed to load');
showToast('Background image failed to load', 'error');
drawPlaceholder();
};

img.src = appState.backgroundImage;
}

function drawPlaceholder() {
const bounds = getPlaceholderBounds();
debugLog('Drawing placeholder', bounds);

if (appState.attendeeImage) {
drawAttendeeImage(bounds);
} else {
drawPlaceholderOutline(bounds);
}
}

function drawAttendeeImage(bounds) {
const img = new Image();
img.crossOrigin = "anonymous";

img.onload = () => {
try {
debugLog('Attendee image loaded for rendering');

ctx.save();

// Create clipping path based on shape
if (appState.placeholder.shape === "circle") {
const cx = bounds.x + bounds.width / 2;
const cy = bounds.y + bounds.height / 2;
const radius = Math.min(bounds.width, bounds.height) / 2;
ctx.beginPath();
ctx.arc(cx, cy, radius, 0, 2 * Math.PI);
ctx.clip();
} else {
ctx.beginPath();
ctx.rect(bounds.x, bounds.y, bounds.width, bounds.height);
ctx.clip();
}

// Calculate scaling to fill the placeholder
const scale = Math.max(bounds.width / img.width, bounds.height / img.height);
const imgWidth = img.width * scale;
const imgHeight = img.height * scale;
const imgX = bounds.x + (bounds.width - imgWidth) / 2;
const imgY = bounds.y + (bounds.height - imgHeight) / 2;

ctx.drawImage(img, imgX, imgY, imgWidth, imgHeight);
ctx.restore();

debugLog('Attendee image rendered successfully');

// Show download section after image is rendered
if (appState.attendeeMode) {
const downloadSection = document.getElementById('ecg-attendee-download-section');
if (downloadSection) {
downloadSection.style.display = 'block';
downloadSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
}
} catch (error) {
debugLog('Attendee image draw error', error);
drawPlaceholderOutline(bounds);
}
};

img.onerror = () => {
debugLog('Attendee image failed to load');
drawPlaceholderOutline(bounds);
};

img.src = appState.attendeeImage;
}

function drawPlaceholderOutline(bounds) {
try {
ctx.save();
ctx.fillStyle = "rgba(34, 197, 94, 0.25)";
ctx.strokeStyle = "rgba(34, 197, 94, 0.8)";
ctx.lineWidth = 2;
ctx.setLineDash([6, 4]);

if (appState.placeholder.shape === "circle") {
const cx = bounds.x + bounds.width / 2;
const cy = bounds.y + bounds.height / 2;
const radius = Math.min(bounds.width, bounds.height) / 2;
ctx.beginPath();
ctx.arc(cx, cy, radius, 0, 2 * Math.PI);
ctx.fill();
ctx.stroke();
} else {
ctx.beginPath();
ctx.rect(bounds.x, bounds.y, bounds.width, bounds.height);
ctx.fill();
ctx.stroke();
}

ctx.restore();
debugLog('Placeholder outline drawn successfully');
} catch (error) {
debugLog('Placeholder outline draw error', error);
}
}

// History management
function pushHistory() {
const newHistory = appState.history.slice(0, appState.historyIndex + 1);
const snapshot = {
backgroundImage: appState.backgroundImage,
placeholder: { ...appState.placeholder },
attendeeImage: appState.attendeeImage,
eventDetails: { ...appState.eventDetails },
dimensions: { ...appState.dimensions },
bgFitMode: appState.bgFitMode
};
newHistory.push(snapshot);
if (newHistory.length > 20) newHistory.shift();
appState.history = newHistory;
appState.historyIndex = newHistory.length - 1;
updateUI();
}

function undo() {
if (appState.historyIndex > 0) {
const snapshot = appState.history[appState.historyIndex - 1];
appState.backgroundImage = snapshot.backgroundImage;
appState.placeholder = { ...snapshot.placeholder };
appState.attendeeImage = snapshot.attendeeImage;
appState.eventDetails = { ...snapshot.eventDetails };
appState.dimensions = { ...snapshot.dimensions };
appState.bgFitMode = snapshot.bgFitMode;
appState.historyIndex--;
renderCanvas();
updateUI();
}
}

function redo() {
if (appState.historyIndex < appState.history.length - 1) {
const snapshot = appState.history[appState.historyIndex + 1];
appState.backgroundImage = snapshot.backgroundImage;
appState.placeholder = { ...snapshot.placeholder };
appState.attendeeImage = snapshot.attendeeImage;
appState.eventDetails = { ...snapshot.eventDetails };
appState.dimensions = { ...snapshot.dimensions };
appState.bgFitMode = snapshot.bgFitMode;
appState.historyIndex++;
renderCanvas();
updateUI();
}
}

// File upload handlers
async function handleFileUpload(file, isAttendee = false) {
try {
validateFile(file);
debugLog('Processing file upload', { isAttendee, fileName: file.name, fileSize: file.size });

const dataUrl = await processImageSync(file);
debugLog('Image processed successfully');

if (isAttendee) {
appState.attendeeImage = dataUrl;
debugLog('Attendee image set');
showToast("🎉 Photo uploaded successfully! Check your personalized cover below.");

// Enable download button for attendees
const downloadBtn = document.getElementById('ecg-attendee-download');
if (downloadBtn) {
downloadBtn.disabled = false;
downloadBtn.style.opacity = '1';
downloadBtn.classList.add('ecg-pulse');
}

// Show success message in upload area
const uploadArea = document.getElementById('ecg-attendee-upload');
if (uploadArea) {
uploadArea.classList.add('ecg-upload-success');
const uploadText = uploadArea.querySelector('.ecg-upload-text');
const uploadSubtext = uploadArea.querySelector('.ecg-upload-subtext');
if (uploadText) uploadText.textContent = '✅ Photo uploaded successfully!';
if (uploadSubtext) uploadSubtext.textContent = 'Your photo is now in the cover below. Click to change photo.';
}
} else {
appState.backgroundImage = dataUrl;
pushHistory();
showToast("Background image uploaded successfully!");
// Show fit mode card when background is uploaded
const fitModeCard = document.getElementById('ecg-fit-mode-card');
if (fitModeCard) fitModeCard.style.display = 'block';
}

// Force canvas render
setTimeout(() => {
renderCanvas();
updateUI();
}, 100);

} catch (error) {
debugLog('File upload error', error);
showToast(error.message, 'error');
}
}

// Navigation functions
function navigateToStep(step) {
appState.currentStep = step;
updateUI();
}

function nextStep() {
if (appState.currentStep === 1) {
const name = document.getElementById('ecg-event-name').value.trim();
const endDate = document.getElementById('ecg-event-end-date').value;
if (!name || !endDate) {
showToast("Please enter event details before proceeding.", 'error');
return;
}
appState.eventDetails.name = name;
appState.eventDetails.endDate = endDate;
} else if (appState.currentStep === 2) {
if (!appState.backgroundImage) {
showToast("Please upload a background image first.", 'error');
return;
}
}

if (appState.currentStep < 3) {
navigateToStep(appState.currentStep + 1);
}
}

function prevStep() {
if (appState.currentStep > 1) {
navigateToStep(appState.currentStep - 1);
}
}

// Action handlers
function handleSave() {
if (!appState.backgroundImage) {
showToast("Please upload a background image first", 'error');
return;
}

try {
const preview = canvas.toDataURL("image/png");
const eventData = {
id: Date.now(),
backgroundImage: appState.backgroundImage,
placeholder: { ...appState.placeholder },
preview,
created: Date.now(),
eventDetails: { ...appState.eventDetails },
dimensions: { ...appState.dimensions },
bgFitMode: appState.bgFitMode
};

const saved = JSON.parse(localStorage.getItem("ecg_events") || "[]");
saved.unshift(eventData);
localStorage.setItem("ecg_events", JSON.stringify(saved.slice(0, 50)));

showToast("Cover saved successfully!");
} catch (error) {
showToast("Failed to save cover", 'error');
}
}

function handleDownload() {
if (!canvas) {
showToast("No image to download", 'error');
return;
}

// For attendees, ensure they have uploaded a photo
if (appState.attendeeMode && !appState.attendeeImage) {
showToast("Please upload your photo first", 'error');
return;
}

try {
const link = document.createElement("a");
const eventName = appState.eventDetails.name || 'event';
const sanitizedEventName = eventName.replace(/[^a-z0-9]/gi, '_').toLowerCase();
const fileName = appState.attendeeMode ?
`${sanitizedEventName}_personalized.png` :
`${sanitizedEventName}_cover.png`;

link.download = fileName;
link.href = canvas.toDataURL("image/png");
link.click();

if (appState.attendeeMode) {
showToast("🎉 Your personalized cover has been downloaded!");
} else {
showToast("Cover downloaded successfully!");
}
} catch (error) {
showToast("Failed to download image", 'error');
}
}

async function handleShare() {
if (!appState.backgroundImage || !appState.eventDetails.name || !appState.eventDetails.endDate) {
showToast("Please complete event setup and upload a background image first", 'error');
return;
}

try {
debugLog('Generating share link with data:', {
eventName: appState.eventDetails.name,
eventEndDate: appState.eventDetails.endDate,
hasBackground: !!appState.backgroundImage,
placeholder: appState.placeholder
});

const response = await fetch(ecgAjax.ajax_url, {
method: 'POST',
headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
body: new URLSearchParams({
action: 'ecg_save_share_final',
nonce: ecgAjax.nonce,
image: appState.backgroundImage,
placeholder: JSON.stringify(appState.placeholder),
eventDetails: JSON.stringify(appState.eventDetails),
dimensions: JSON.stringify(appState.dimensions),
bgFitMode: appState.bgFitMode
})
});

const result = await response.json();
debugLog('Share link response:', result);

if (result.success) {
const token = result.data.token;
const currentPath = window.location.pathname;
const shareUrl = `${window.location.origin}${currentPath}?ecg_token=${token}`;

document.getElementById('ecg-share-link').value = shareUrl;
document.getElementById('ecg-share-modal').style.display = 'flex';

// Show expiry info
const eventDate = new Date(appState.eventDetails.endDate);
const expiryText = `Link expires on: ${eventDate.toLocaleDateString()} (when event ends)`;
showToast(`Share link generated! ${expiryText}`);
} else {
showToast(result.data || 'Failed to generate share link', 'error');
}
} catch (error) {
debugLog('Share link error:', error);
showToast("Failed to generate share link", 'error');
}
}

// Copy link functionality
async function copyShareLink() {
const linkInput = document.getElementById("ecg-share-link");
if (!linkInput) return;

try {
if (navigator.clipboard && window.isSecureContext) {
await navigator.clipboard.writeText(linkInput.value);
} else {
linkInput.select();
document.execCommand('copy');
}
showToast("Link copied to clipboard!");
} catch (error) {
showToast("Failed to copy link. Please copy manually.", 'error');
}
}

// FIXED UI UPDATE FUNCTION - PROPER ATTENDEE MODE SWITCHING
function updateUI() {
debugLog('Updating UI', { attendeeMode: appState.attendeeMode, currentStep: appState.currentStep });

// Get all the UI elements we need to control
const stepperContainer = document.querySelector('.ecg-stepper');
const navigationContainer = document.querySelector('.ecg-navigation');
const historyControls = document.querySelector('.ecg-history');
const previewPanel = document.querySelector('.ecg-preview-panel');
const attendeeControls = document.getElementById("ecg-attendee-controls");

// Get all step content elements (creator mode steps)
const allStepContents = document.querySelectorAll('.ecg-step-content');

if (appState.attendeeMode) {
debugLog('Switching to attendee mode - hiding creator elements');

// HIDE ALL CREATOR ELEMENTS
if (stepperContainer) stepperContainer.style.display = 'none';
if (navigationContainer) navigationContainer.style.display = 'none';
if (historyControls) historyControls.style.display = 'none';
if (previewPanel) previewPanel.style.display = 'none';

// Hide all creator step content
allStepContents.forEach(stepEl => {
stepEl.style.display = 'none';
});

// SHOW ATTENDEE CONTROLS
if (attendeeControls) {
attendeeControls.style.display = 'block';
attendeeControls.classList.remove('ecg-hidden');
}

// Update container class for attendee styling
const container = document.querySelector('.ecg-container');
if (container) container.classList.add('attendee-mode');

// Update download button state
const downloadBtn = document.getElementById('ecg-attendee-download');
if (downloadBtn) {
if (appState.attendeeImage) {
downloadBtn.disabled = false;
downloadBtn.style.opacity = '1';
} else {
downloadBtn.disabled = true;
downloadBtn.style.opacity = '0.5';
}
}

// Show/hide download section based on image upload
const downloadSection = document.getElementById('ecg-attendee-download-section');
if (downloadSection) {
downloadSection.style.display = appState.attendeeImage ? 'block' : 'none';
}

} else {
debugLog('Creator mode active - showing creator elements');

// SHOW ALL CREATOR ELEMENTS
if (stepperContainer) stepperContainer.style.display = 'flex';
if (navigationContainer) navigationContainer.style.display = 'flex';
if (historyControls) historyControls.style.display = 'flex';
if (previewPanel) previewPanel.style.display = 'block';

// HIDE ATTENDEE CONTROLS
if (attendeeControls) {
attendeeControls.style.display = 'none';
attendeeControls.classList.add('ecg-hidden');
}

const container = document.querySelector('.ecg-container');
if (container) container.classList.remove('attendee-mode');

// Update step visibility for creator mode
allStepContents.forEach(panel => {
panel.style.display = 'none';
});
const currentStepEl = document.getElementById(`ecg-step-${appState.currentStep}`);
if (currentStepEl) currentStepEl.style.display = 'block';

// Update stepper
document.querySelectorAll('.ecg-stepper-item').forEach((item, index) => {
const stepNumber = index + 1;
item.classList.remove('active', 'completed');
if (stepNumber < appState.currentStep) {
item.classList.add('completed');
} else if (stepNumber === appState.currentStep) {
item.classList.add('active');
}
});

// Update progress bar
const progressBar = document.getElementById('ecg-stepper-progress');
if (progressBar) {
const progressWidth = ((appState.currentStep - 1) / 2) * 100;
progressBar.style.width = `${progressWidth}%`;
}

// Update navigation buttons
const prevBtn = document.getElementById('ecg-prev-btn');
const nextBtn = document.getElementById('ecg-next-btn');

if (prevBtn) prevBtn.style.display = appState.currentStep === 1 ? 'none' : 'inline-flex';
if (nextBtn) nextBtn.style.display = appState.currentStep === 3 ? 'none' : 'inline-flex';
}

// Update dimension buttons
document.querySelectorAll(".ecg-dimension-btn").forEach(btn => {
btn.classList.toggle("active", btn.dataset.dimension === appState.dimensions.name.toLowerCase());
});

// Update shape buttons
document.querySelectorAll(".ecg-shape-btn").forEach(btn => {
btn.classList.toggle("active", btn.dataset.shape === appState.placeholder.shape);
});

// Update fit buttons
document.querySelectorAll(".ecg-fit-btn").forEach(btn => {
btn.classList.toggle("active", btn.dataset.mode === appState.bgFitMode);
});

// Update size slider
const sizeSlider = document.getElementById("ecg-size-slider");
const sizeLabel = document.getElementById("ecg-size-label");
if (sizeSlider && sizeLabel) {
sizeSlider.value = appState.placeholder.size;
sizeLabel.textContent = `Placeholder Size: ${appState.placeholder.size}%`;
}

// Update undo/redo buttons
const undoBtn = document.getElementById("ecg-undo");
const redoBtn = document.getElementById("ecg-redo");
if (undoBtn) undoBtn.disabled = appState.historyIndex <= 0;
if (redoBtn) redoBtn.disabled = appState.historyIndex >= appState.history.length - 1;

// Update event details
const nameInput = document.getElementById('ecg-event-name');
const endDateInput = document.getElementById('ecg-event-end-date');
if (nameInput) nameInput.value = appState.eventDetails.name || '';
if (endDateInput) endDateInput.value = appState.eventDetails.endDate || '';

// Update event info display for both creator and attendee views
const eventInfoEls = document.querySelectorAll('#ecg-event-info, #ecg-event-info-attendee');
eventInfoEls.forEach(el => {
if (appState.eventDetails.name) {
el.innerHTML = `
<h3 class="ecg-event-title">${appState.eventDetails.name}</h3>
${appState.eventDetails.endDate ? `<p class="ecg-event-date">Ends on: ${new Date(appState.eventDetails.endDate).toLocaleDateString()}</p>` : ''}
`;
el.style.display = 'block';
} else {
el.style.display = 'none';
}
});

// Update canvas info for both creator and attendee views
const canvasInfoEls = document.querySelectorAll('#ecg-canvas-info, #ecg-canvas-info-attendee');
canvasInfoEls.forEach(el => {
el.textContent = `${appState.dimensions.name} • ${appState.dimensions.width} × ${appState.dimensions.height}px`;
});

debugLog('UI update complete');
}

// Canvas interaction handlers
function handleCanvasMouseDown(e) {
if (appState.attendeeMode) return; // Only allow dragging in creator mode

const rect = canvas.getBoundingClientRect();
const scaleX = canvas.width / rect.width;
const scaleY = canvas.height / rect.height;
const x = (e.clientX - rect.left) * scaleX;
const y = (e.clientY - rect.top) * scaleY;

debugLog('Canvas mouse down at:', { x, y, attendeeMode: appState.attendeeMode });

if (isPointInPlaceholder(x, y)) {
appState.isDragging = true;
const centerX = appState.placeholder.x * canvas.width;
const centerY = appState.placeholder.y * canvas.height;
appState.dragOffset = { x: x - centerX, y: y - centerY };
canvas.style.cursor = 'grabbing';
debugLog('Started dragging placeholder');
}
}

function handleCanvasMouseMove(e) {
if (appState.attendeeMode) return;

const rect = canvas.getBoundingClientRect();
const scaleX = canvas.width / rect.width;
const scaleY = canvas.height / rect.height;
const x = (e.clientX - rect.left) * scaleX;
const y = (e.clientY - rect.top) * scaleY;

// Update cursor based on hover state
if (!appState.isDragging) {
if (isPointInPlaceholder(x, y)) {
canvas.style.cursor = 'grab';
} else {
canvas.style.cursor = 'default';
}
}

if (!appState.isDragging) return;

const newCenterX = x - appState.dragOffset.x;
const newCenterY = y - appState.dragOffset.y;

const placeholderWidth = (appState.placeholder.size / 100) * canvas.width;
let ratio = 1;
if (appState.placeholder.shape === "portrait") ratio = 1.4;
const placeholderHeight = placeholderWidth * ratio;

const clampedX = Math.max(
placeholderWidth / 2,
Math.min(canvas.width - placeholderWidth / 2, newCenterX)
);
const clampedY = Math.max(
placeholderHeight / 2,
Math.min(canvas.height - placeholderHeight / 2, newCenterY)
);

appState.placeholder.x = clampedX / canvas.width;
appState.placeholder.y = clampedY / canvas.height;
renderCanvas();
}

function handleCanvasMouseUp() {
if (appState.isDragging) {
appState.isDragging = false;
canvas.style.cursor = 'default';
pushHistory();
debugLog('Finished dragging placeholder');
}
}

// Touch events for mobile
function handleCanvasTouchStart(e) {
e.preventDefault();
const touch = e.touches[0];
const mouseEvent = new MouseEvent("mousedown", {
clientX: touch.clientX,
clientY: touch.clientY
});
canvas.dispatchEvent(mouseEvent);
}

function handleCanvasTouchMove(e) {
e.preventDefault();
const touch = e.touches[0];
const mouseEvent = new MouseEvent("mousemove", {
clientX: touch.clientX,
clientY: touch.clientY
});
canvas.dispatchEvent(mouseEvent);
}

function handleCanvasTouchEnd(e) {
e.preventDefault();
const mouseEvent = new MouseEvent("mouseup", {});
canvas.dispatchEvent(mouseEvent);
}

// Drag and drop handlers
function handleDragOver(e) {
e.preventDefault();
e.stopPropagation();
}

function handleDrop(e, isAttendee = false) {
e.preventDefault();
e.stopPropagation();

const files = Array.from(e.dataTransfer.files);
const imageFile = files.find(file => ALLOWED_TYPES.includes(file.type));

if (imageFile) {
handleFileUpload(imageFile, isAttendee);
} else {
showToast("Please drop a valid image file", 'error');
}
}

// Initialize the application
async function init() {
debugLog('Initializing Event Cover Generator - ATTENDEE FIXED v3.2.1');

// Determine attendee vs creator mode by inspecting the URL token.
const urlParams = new URLSearchParams(window.location.search);
const token = urlParams.get('ecg_token');
debugLog('URL token:', token);

// Reset mode; will be set to true when valid shared data is loaded
appState.attendeeMode = false;

// Attempt to load shared data when a token is present
let sharedData = null;
if (token) {
debugLog('Token detected - checking for shared data');
if (window.ecg_shared_data) {
// Inline script injected by PHP already available
sharedData = window.ecg_shared_data;
debugLog('Using inline shared data', sharedData);
} else {
// Fallback: fetch shared data via AJAX
debugLog('No inline data, fetching via AJAX');
sharedData = await fetchSharedData(token);
}

if (sharedData && sharedData.image && sharedData.placeholder) {
debugLog('Valid shared data found - activating attendee mode');
// Populate state from shared data for attendee mode
appState.backgroundImage = sharedData.image;
appState.placeholder = { ...sharedData.placeholder };
appState.attendeeMode = true; // THIS IS THE KEY FIX
appState.attendeeImage = null;
appState.eventDetails = sharedData.eventDetails || { name: '', endDate: '' };
appState.dimensions = sharedData.dimensions || DIMENSIONS.square;
appState.bgFitMode = sharedData.bgFitMode || 'fill';
appState.currentStep = 3;

// Update page title and subtitle for attendee
const titleEl = document.querySelector('.ecg-title');
if (titleEl && appState.eventDetails.name) {
titleEl.textContent = `${appState.eventDetails.name} - Upload Your Photo`;
}
const subtitleEl = document.querySelector('.ecg-subtitle');
if (subtitleEl) {
const eventDate = new Date(appState.eventDetails.endDate);
subtitleEl.textContent = `Upload your photo before ${eventDate.toLocaleDateString()}`;
}

debugLog('Attendee mode activated successfully');
showToast(`Welcome! Upload your photo to create your personalized ${appState.eventDetails.name} cover.`);
} else if (token) {
debugLog('No valid shared data found for token', token);
// If no valid shared data, remain in creator mode but notify user
showToast('Invalid or expired link. Please check the URL.', 'error');
}
} else {
debugLog('No token found - creator mode');
}

// In creator mode, initialize history once
if (!appState.attendeeMode) {
debugLog('Creator mode - initializing history');
pushHistory();
}

// Now select the appropriate canvas based on the determined mode. In
// attendee mode, prefer the attendee canvas; otherwise use the creator
// canvas. If neither exist, fall back to whichever is available.
const creatorCanvas = document.getElementById('ecg-canvas');
const attendeeCanvasEl = document.getElementById('ecg-canvas-attendee');
if (appState.attendeeMode && attendeeCanvasEl) {
canvas = attendeeCanvasEl;
debugLog('Using attendee canvas');
} else if (creatorCanvas) {
canvas = creatorCanvas;
debugLog('Using creator canvas');
} else {
canvas = attendeeCanvasEl;
debugLog('Fallback to attendee canvas');
}
if (!canvas) {
debugLog('Canvas not found!');
return;
}
ctx = canvas.getContext('2d');
debugLog('Canvas and context initialized', { canvas: !!canvas, ctx: !!ctx });

// Canvas event listeners
canvas.addEventListener("mousedown", handleCanvasMouseDown);
canvas.addEventListener("mousemove", handleCanvasMouseMove);
canvas.addEventListener("mouseup", handleCanvasMouseUp);
canvas.addEventListener("mouseleave", handleCanvasMouseUp);

// Touch events for mobile
canvas.addEventListener("touchstart", handleCanvasTouchStart);
canvas.addEventListener("touchmove", handleCanvasTouchMove);
canvas.addEventListener("touchend", handleCanvasTouchEnd);

// File upload handlers
const bgFileInput = document.getElementById("ecg-bg-file");
const attendeeFileInput = document.getElementById("ecg-attendee-file");

if (bgFileInput) {
bgFileInput.addEventListener("change", (e) => {
const file = e.target.files[0];
if (file) handleFileUpload(file);
});
}

if (attendeeFileInput) {
attendeeFileInput.addEventListener("change", (e) => {
const file = e.target.files[0];
if (file) {
debugLog('Attendee file selected', { fileName: file.name });
handleFileUpload(file, true);
}
});
}

// Drag and drop
const bgUpload = document.getElementById("ecg-bg-upload");
const attendeeUpload = document.getElementById("ecg-attendee-upload");

if (bgUpload) {
bgUpload.addEventListener("dragover", handleDragOver);
bgUpload.addEventListener("drop", (e) => handleDrop(e, false));
bgUpload.addEventListener("click", () => bgFileInput?.click());
}

if (attendeeUpload) {
attendeeUpload.addEventListener("dragover", handleDragOver);
attendeeUpload.addEventListener("drop", (e) => handleDrop(e, true));
attendeeUpload.addEventListener("click", () => {
debugLog('Attendee upload area clicked');
attendeeFileInput?.click();
});
}

// Button event listeners
document.addEventListener('click', (e) => {
// Navigation
if (e.target.id === 'ecg-next-btn') nextStep();
if (e.target.id === 'ecg-prev-btn') prevStep();

// Actions
if (e.target.id === 'ecg-save') handleSave();
if (e.target.id === 'ecg-download' || e.target.id === 'ecg-attendee-download') {
debugLog('Download button clicked', { attendeeMode: appState.attendeeMode, hasAttendeeImage: !!appState.attendeeImage });
handleDownload();
}
if (e.target.id === 'ecg-share') handleShare();
if (e.target.id === 'ecg-copy-link') copyShareLink();

// History
if (e.target.id === 'ecg-undo') undo();
if (e.target.id === 'ecg-redo') redo();

// Modal
if (e.target.id === 'ecg-close-modal' || e.target.classList.contains('ecg-modal')) {
document.getElementById('ecg-share-modal').style.display = 'none';
}

// Dimension buttons (only in creator mode)
if (!appState.attendeeMode && e.target.classList.contains('ecg-dimension-btn')) {
const dimension = e.target.dataset.dimension;
appState.dimensions = DIMENSIONS[dimension];
pushHistory();
renderCanvas();
updateUI();
}

// Shape buttons (only in creator mode)
if (!appState.attendeeMode && e.target.classList.contains('ecg-shape-btn')) {
appState.placeholder.shape = e.target.dataset.shape;
pushHistory();
renderCanvas();
updateUI();
}

// Fit buttons (only in creator mode)
if (!appState.attendeeMode && e.target.classList.contains('ecg-fit-btn')) {
appState.bgFitMode = e.target.dataset.mode;
pushHistory();
renderCanvas();
updateUI();
}

// Stepper navigation (only in creator mode)
if (!appState.attendeeMode && e.target.closest('.ecg-stepper-item')) {
const step = parseInt(e.target.closest('.ecg-stepper-item').dataset.step);
if (step <= appState.currentStep) {
navigateToStep(step);
}
}
});

// Size slider (only in creator mode)
const sizeSlider = document.getElementById("ecg-size-slider");
if (sizeSlider) {
sizeSlider.addEventListener("input", (e) => {
if (!appState.attendeeMode) {
appState.placeholder.size = parseInt(e.target.value);
renderCanvas();
updateUI();
}
});
sizeSlider.addEventListener("mouseup", () => {
if (!appState.attendeeMode) pushHistory();
});
sizeSlider.addEventListener("touchend", () => {
if (!appState.attendeeMode) pushHistory();
});
}

// Keyboard shortcuts (only in creator mode)
document.addEventListener("keydown", (e) => {
if (!appState.attendeeMode && (e.ctrlKey || e.metaKey)) {
switch (e.key.toLowerCase()) {
case "z":
e.preventDefault();
if (e.shiftKey) redo(); else undo();
break;
case "y":
e.preventDefault();
redo();
break;
case "s":
e.preventDefault();
handleSave();
break;
}
}
});

// Initial render and UI update
debugLog('Starting initial render and UI update');
renderCanvas();
updateUI(); // This will now properly switch to attendee mode if needed

debugLog('Initialization complete', {
attendeeMode: appState.attendeeMode,
hasBackground: !!appState.backgroundImage,
eventName: appState.eventDetails.name
});
}

// Start the app when DOM is ready
if (document.readyState === "loading") {
document.addEventListener("DOMContentLoaded", init);
} else {
init();
}
})();
JS'
);

wp_enqueue_script('ecg-final-js');

// Enqueue improved styles for better attendee UX
wp_register_style('ecg-final-css', false);
wp_enqueue_style('ecg-final-css');
wp_add_inline_style('ecg-final-css', <<<'CSS'
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

:root {
--ecg-primary: #10b981;
--ecg-primary-dark: #059669;
--ecg-primary-light: #34d399;
--ecg-secondary: #3b82f6;
--ecg-accent: #8b5cf6;
--ecg-success: #10b981;
--ecg-error: #ef4444;
--ecg-warning: #f59e0b;
--ecg-text: #111827;
--ecg-text-light: #6b7280;
--ecg-bg: #ffffff;
--ecg-bg-light: #f9fafb;
--ecg-border: #e5e7eb;
--ecg-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
--ecg-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
}

.ecg-container {
max-width: 1200px;
margin: 0 auto;
padding: 2rem;
font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
background: linear-gradient(135deg, #f9fafb 0%, #e0f2fe 100%);
min-height: 100vh;
}

.ecg-header {
text-align: center;
margin-bottom: 3rem;
}

.ecg-title {
font-size: 2.5rem;
font-weight: 700;
background: linear-gradient(135deg, var(--ecg-primary), var(--ecg-secondary));
-webkit-background-clip: text;
-webkit-text-fill-color: transparent;
background-clip: text;
margin-bottom: 0.5rem;
}

.ecg-subtitle {
font-size: 1.125rem;
color: var(--ecg-text-light);
margin: 0;
}

/* IMPROVED ATTENDEE LAYOUT */
.ecg-container.attendee-mode {
max-width: 900px;
padding: 1rem;
}

.ecg-container.attendee-mode .ecg-main-grid {
grid-template-columns: 1fr;
gap: 1.5rem;
}

/* Attendee Step Flow */
.ecg-attendee-step {
background: var(--ecg-bg);
border-radius: 1rem;
box-shadow: var(--ecg-shadow);
border: 1px solid var(--ecg-border);
margin-bottom: 1.5rem;
overflow: hidden;
}

.ecg-attendee-step-header {
background: linear-gradient(135deg, var(--ecg-secondary) 0%, #2563eb 100%);
color: white;
padding: 1.5rem;
text-align: center;
}

.ecg-attendee-step-number {
display: inline-flex;
align-items: center;
justify-content: center;
width: 2.5rem;
height: 2.5rem;
background: rgba(255, 255, 255, 0.2);
border-radius: 50%;
font-weight: 700;
font-size: 1.25rem;
margin-bottom: 0.5rem;
}

.ecg-attendee-step-title {
font-size: 1.5rem;
font-weight: 700;
margin: 0;
}

.ecg-attendee-step-description {
font-size: 1rem;
opacity: 0.9;
margin: 0.5rem 0 0 0;
}

.ecg-attendee-step-content {
padding: 2rem;
}

/* Enhanced Upload Area for Attendees */
.ecg-attendee-upload-area {
border: 3px dashed var(--ecg-secondary);
border-radius: 1.5rem;
padding: 3rem 2rem;
text-align: center;
cursor: pointer;
transition: all 0.3s ease;
background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
position: relative;
overflow: hidden;
min-height: 200px;
display: flex;
flex-direction: column;
align-items: center;
justify-content: center;
}

.ecg-attendee-upload-area:hover {
border-color: #1e40af;
background: linear-gradient(135deg, #bfdbfe 0%, #93c5fd 100%);
transform: translateY(-3px);
box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
}

.ecg-attendee-upload-area.ecg-upload-success {
border-color: var(--ecg-success);
background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
}

.ecg-attendee-upload-area .ecg-upload-icon {
width: 6rem;
height: 6rem;
background: var(--ecg-secondary);
border-radius: 50%;
display: flex;
align-items: center;
justify-content: center;
color: white;
font-size: 2.5rem;
margin-bottom: 1.5rem;
animation: bounce 2s infinite;
}

.ecg-upload-success .ecg-upload-icon {
background: var(--ecg-success);
}

@keyframes bounce {
0%, 20%, 53%, 80%, 100% { transform: translate3d(0,0,0); }
40%, 43% { transform: translate3d(0,-15px,0); }
70% { transform: translate3d(0,-7px,0); }
90% { transform: translate3d(0,-2px,0); }
}

.ecg-attendee-upload-area .ecg-upload-text {
font-size: 1.5rem;
font-weight: 700;
color: var(--ecg-secondary);
margin-bottom: 0.75rem;
}

.ecg-upload-success .ecg-upload-text {
color: var(--ecg-success);
}

.ecg-attendee-upload-area .ecg-upload-subtext {
font-size: 1.125rem;
color: #1e40af;
font-weight: 500;
}

.ecg-upload-success .ecg-upload-subtext {
color: #059669;
}

/* Preview Section */
.ecg-preview-section {
margin: 2rem 0;
}

.ecg-preview-title {
font-size: 1.25rem;
font-weight: 600;
color: var(--ecg-text);
margin-bottom: 1rem;
text-align: center;
}

/* Download Section - Only shown after upload */
.ecg-attendee-download-section {
display: none;
animation: slideInUp 0.5s ease-out;
}

@keyframes slideInUp {
from {
opacity: 0;
transform: translateY(30px);
}
to {
opacity: 1;
transform: translateY(0);
}
}

/* Ad Section Styles */
.ecg-ad-section {
background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
border: 1px solid #f59e0b;
border-radius: 1rem;
padding: 1.5rem;
margin: 2rem 0;
text-align: center;
position: relative;
overflow: hidden;
}

.ecg-ad-section::before {
content: '';
position: absolute;
top: -50%;
left: -50%;
width: 200%;
height: 200%;
background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
animation: shimmer 3s infinite;
}

@keyframes shimmer {
0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
}

.ecg-ad-header {
font-size: 1.25rem;
font-weight: 700;
color: #92400e;
margin-bottom: 0.5rem;
display: flex;
align-items: center;
justify-content: center;
gap: 0.5rem;
}

.ecg-ad-content {
font-size: 1rem;
color: #92400e;
margin-bottom: 1rem;
position: relative;
z-index: 1;
}

.ecg-ad-cta {
display: inline-flex;
align-items: center;
gap: 0.5rem;
padding: 0.75rem 1.5rem;
background: linear-gradient(135deg, #f59e0b, #d97706);
color: white;
text-decoration: none;
border-radius: 0.5rem;
font-weight: 600;
transition: all 0.3s ease;
position: relative;
z-index: 1;
}

.ecg-ad-cta:hover {
transform: translateY(-2px);
box-shadow: 0 10px 20px rgba(0,0,0,0.2);
color: white;
}

/* Attendee Ad - Different styling */
.ecg-attendee-ad {
background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
border-color: #22c55e;
}

.ecg-attendee-ad .ecg-ad-header {
color: #15803d;
}

.ecg-attendee-ad .ecg-ad-content {
color: #15803d;
}

.ecg-attendee-ad .ecg-ad-cta {
background: linear-gradient(135deg, #22c55e, #16a34a);
}

/* Stepper Styles */
.ecg-stepper {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 2rem;
position: relative;
padding: 0 1rem;
}

.ecg-stepper-line {
position: absolute;
top: 50%;
left: 0;
right: 0;
height: 2px;
background: var(--ecg-border);
transform: translateY(-50%);
z-index: 1;
}

.ecg-stepper-progress {
position: absolute;
top: 50%;
left: 0;
height: 2px;
background: linear-gradient(90deg, var(--ecg-primary), var(--ecg-secondary));
transform: translateY(-50%);
transition: width 0.5s ease;
z-index: 2;
width: 0%;
}

.ecg-stepper-item {
display: flex;
flex-direction: column;
align-items: center;
gap: 0.5rem;
position: relative;
z-index: 3;
cursor: pointer;
transition: transform 0.2s ease;
}

.ecg-stepper-item:hover {
transform: scale(1.05);
}

.ecg-stepper-icon {
width: 3rem;
height: 3rem;
border-radius: 50%;
background: var(--ecg-bg);
border: 2px solid var(--ecg-border);
display: flex;
align-items: center;
justify-content: center;
transition: all 0.3s ease;
color: var(--ecg-text-light);
font-weight: 600;
}

.ecg-stepper-item.completed .ecg-stepper-icon {
border-color: var(--ecg-primary);
color: var(--ecg-primary);
background: #ecfdf5;
}

.ecg-stepper-item.active .ecg-stepper-icon {
border-color: var(--ecg-primary);
color: white;
background: var(--ecg-primary);
box-shadow: var(--ecg-shadow);
}

.ecg-stepper-label {
font-size: 0.875rem;
font-weight: 500;
color: var(--ecg-text-light);
text-align: center;
}

.ecg-stepper-item.completed .ecg-stepper-label,
.ecg-stepper-item.active .ecg-stepper-label {
color: var(--ecg-primary);
}

/* Main Layout */
.ecg-main-grid {
display: grid;
grid-template-columns: 1fr 1fr;
gap: 2rem;
margin-top: 2rem;
}

@media (max-width: 768px) {
.ecg-main-grid {
grid-template-columns: 1fr;
gap: 1.5rem;
}

.ecg-container {
padding: 1rem;
}

.ecg-title {
font-size: 2rem;
}

.ecg-attendee-upload-area {
padding: 2rem 1rem;
min-height: 150px;
}

.ecg-attendee-upload-area .ecg-upload-icon {
width: 4rem;
height: 4rem;
font-size: 2rem;
}

.ecg-attendee-upload-area .ecg-upload-text {
font-size: 1.25rem;
}
}

/* Card Styles */
.ecg-card {
background: var(--ecg-bg);
border-radius: 1rem;
box-shadow: var(--ecg-shadow);
overflow: hidden;
border: 1px solid var(--ecg-border);
margin-bottom: 1.5rem;
}

.ecg-card-header {
padding: 1.5rem;
border-bottom: 1px solid var(--ecg-border);
background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
}

.ecg-card-title {
font-size: 1.25rem;
font-weight: 600;
color: var(--ecg-text);
margin: 0;
display: flex;
align-items: center;
gap: 0.5rem;
}

.ecg-card-content {
padding: 1.5rem;
}

/* Step Content */
.ecg-step-content {
display: none;
}

.ecg-step-content.active {
display: block;
}

/* Form Elements */
.ecg-form-group {
margin-bottom: 1.5rem;
}

.ecg-label {
display: block;
font-size: 0.875rem;
font-weight: 500;
color: var(--ecg-text);
margin-bottom: 0.5rem;
}

.ecg-input {
width: 100%;
padding: 0.75rem;
border: 1px solid var(--ecg-border);
border-radius: 0.5rem;
font-size: 1rem;
transition: all 0.2s ease;
background: var(--ecg-bg);
}

.ecg-input:focus {
outline: none;
border-color: var(--ecg-primary);
box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
}

/* Upload Area - ENHANCED FOR ATTENDEES */
.ecg-upload {
border: 2px dashed var(--ecg-primary-light);
border-radius: 1rem;
padding: 2rem;
text-align: center;
cursor: pointer;
transition: all 0.3s ease;
background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
position: relative;
overflow: hidden;
}

.ecg-upload:hover {
border-color: var(--ecg-primary);
background: linear-gradient(135deg, #d1fae5 0%, #ecfdf5 100%);
transform: translateY(-2px);
box-shadow: var(--ecg-shadow-lg);
}

.ecg-upload-icon {
width: 4rem;
height: 4rem;
margin: 0 auto 1rem;
background: var(--ecg-primary);
border-radius: 50%;
display: flex;
align-items: center;
justify-content: center;
color: white;
font-size: 1.5rem;
animation: pulse 2s infinite;
}

@keyframes pulse {
0%, 100% { transform: scale(1); }
50% { transform: scale(1.05); }
}

.ecg-upload-text {
font-size: 1.125rem;
font-weight: 600;
color: var(--ecg-primary);
margin-bottom: 0.5rem;
}

.ecg-upload-subtext {
font-size: 0.875rem;
color: var(--ecg-text-light);
}

/* Button Styles */
.ecg-btn {
display: inline-flex;
align-items: center;
justify-content: center;
gap: 0.5rem;
padding: 0.75rem 1.5rem;
border: none;
border-radius: 0.5rem;
font-size: 0.875rem;
font-weight: 500;
cursor: pointer;
transition: all 0.2s ease;
text-decoration: none;
font-family: inherit;
}

.ecg-btn:disabled {
opacity: 0.5;
cursor: not-allowed;
}

.ecg-btn-primary {
background: linear-gradient(135deg, var(--ecg-primary), var(--ecg-primary-dark));
color: white;
box-shadow: var(--ecg-shadow);
}

.ecg-btn-primary:hover:not(:disabled) {
transform: translateY(-1px);
box-shadow: var(--ecg-shadow-lg);
}

.ecg-btn-secondary {
background: linear-gradient(135deg, var(--ecg-secondary), #2563eb);
color: white;
box-shadow: var(--ecg-shadow);
}

.ecg-btn-secondary:hover:not(:disabled) {
transform: translateY(-1px);
box-shadow: var(--ecg-shadow-lg);
}

.ecg-btn-outline {
background: var(--ecg-bg);
color: var(--ecg-text);
border: 1px solid var(--ecg-border);
}

.ecg-btn-outline:hover:not(:disabled) {
background: var(--ecg-bg-light);
border-color: var(--ecg-primary);
}

/* Button Groups */
.ecg-btn-group {
display: grid;
grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
gap: 0.75rem;
margin-bottom: 1.5rem;
}

.ecg-dimension-btn,
.ecg-shape-btn,
.ecg-fit-btn {
padding: 1rem;
border: 1px solid var(--ecg-border);
border-radius: 0.75rem;
background: var(--ecg-bg);
cursor: pointer;
transition: all 0.2s ease;
text-align: center;
display: flex;
flex-direction: column;
align-items: center;
gap: 0.5rem;
font-weight: 500;
}

.ecg-dimension-btn:hover,
.ecg-shape-btn:hover,
.ecg-fit-btn:hover {
border-color: var(--ecg-primary);
background: #f0fdf4;
transform: translateY(-1px);
}

.ecg-dimension-btn.active,
.ecg-shape-btn.active,
.ecg-fit-btn.active {
background: linear-gradient(135deg, var(--ecg-primary), var(--ecg-primary-dark));
border-color: var(--ecg-primary);
color: white;
transform: scale(1.05);
box-shadow: var(--ecg-shadow);
}

/* Slider */
.ecg-slider-container {
margin: 1.5rem 0;
}

.ecg-slider-label {
display: block;
font-size: 0.875rem;
font-weight: 500;
color: var(--ecg-text);
margin-bottom: 0.75rem;
}

.ecg-slider {
width: 100%;
height: 6px;
border-radius: 3px;
background: var(--ecg-border);
outline: none;
-webkit-appearance: none;
cursor: pointer;
}

.ecg-slider::-webkit-slider-thumb {
-webkit-appearance: none;
width: 20px;
height: 20px;
border-radius: 50%;
background: var(--ecg-primary);
cursor: pointer;
border: 2px solid white;
box-shadow: var(--ecg-shadow);
}

.ecg-slider::-moz-range-thumb {
width: 20px;
height: 20px;
border-radius: 50%;
background: var(--ecg-primary);
cursor: pointer;
border: 2px solid white;
box-shadow: var(--ecg-shadow);
}

/* Canvas */
.ecg-canvas-container {
position: relative;
width: 100%;
border: 1px solid var(--ecg-border);
border-radius: 0.75rem;
overflow: hidden;
background: var(--ecg-bg-light);
box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
}

.ecg-canvas {
width: 100%;
height: auto;
display: block;
cursor: default;
}

/* Canvas cursor states for creator mode */
.ecg-container:not(.attendee-mode) .ecg-canvas {
cursor: grab;
}

.ecg-container:not(.attendee-mode) .ecg-canvas:active {
cursor: grabbing;
}

.ecg-canvas-hint {
position: absolute;
top: 0.5rem;
right: 0.5rem;
background: rgba(0, 0, 0, 0.7);
color: white;
padding: 0.25rem 0.5rem;
border-radius: 0.25rem;
font-size: 0.75rem;
}

/* Event Info */
.ecg-event-info {
text-align: center;
margin-bottom: 1rem;
padding: 1rem;
background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
border-radius: 0.75rem;
border: 1px solid #bbf7d0;
display: none;
}

.ecg-event-title {
font-size: 1.25rem;
font-weight: 600;
color: var(--ecg-text);
margin: 0 0 0.25rem 0;
}

.ecg-event-date {
font-size: 0.875rem;
color: var(--ecg-text-light);
margin: 0;
}

/* Navigation */
.ecg-navigation {
display: flex;
justify-content: space-between;
align-items: center;
margin-top: 2rem;
padding-top: 1.5rem;
border-top: 1px solid var(--ecg-border);
}

.ecg-final-actions {
display: grid;
grid-template-columns: repeat(3, 1fr);
gap: 0.75rem;
width: 100%;
}

@media (max-width: 640px) {
.ecg-final-actions {
grid-template-columns: 1fr;
}
}

/* History Controls */
.ecg-history {
display: flex;
gap: 0.5rem;
margin-bottom: 1rem;
justify-content: flex-end;
}

.ecg-history-btn {
padding: 0.5rem;
border: 1px solid var(--ecg-border);
border-radius: 0.5rem;
background: var(--ecg-bg);
cursor: pointer;
transition: all 0.2s ease;
color: var(--ecg-text-light);
}

.ecg-history-btn:hover:not(:disabled) {
border-color: var(--ecg-primary);
color: var(--ecg-primary);
}

.ecg-history-btn:disabled {
opacity: 0.5;
cursor: not-allowed;
}

/* Modal */
.ecg-modal {
position: fixed;
top: 0;
left: 0;
width: 100%;
height: 100%;
background: rgba(0, 0, 0, 0.5);
display: none;
align-items: center;
justify-content: center;
z-index: 1000;
padding: 1rem;
}

.ecg-modal-content {
background: var(--ecg-bg);
border-radius: 1rem;
padding: 2rem;
max-width: 500px;
width: 100%;
position: relative;
box-shadow: var(--ecg-shadow-lg);
}

.ecg-modal-close {
position: absolute;
top: 1rem;
right: 1rem;
background: none;
border: none;
font-size: 1.5rem;
cursor: pointer;
color: var(--ecg-text-light);
padding: 0.25rem;
}

.ecg-modal-close:hover {
color: var(--ecg-text);
}

.ecg-modal-title {
font-size: 1.25rem;
font-weight: 600;
color: var(--ecg-text);
margin-bottom: 1rem;
display: flex;
align-items: center;
gap: 0.5rem;
}

.ecg-link-container {
display: flex;
gap: 0.5rem;
margin: 1rem 0;
}

.ecg-link-input {
flex: 1;
padding: 0.75rem;
border: 1px solid var(--ecg-border);
border-radius: 0.5rem;
background: var(--ecg-bg-light);
font-family: monospace;
font-size: 0.875rem;
}

/* Toast Notifications */
.ecg-toast {
position: fixed;
top: 2rem;
right: 2rem;
padding: 1rem 1.5rem;
border-radius: 0.5rem;
color: white;
font-weight: 500;
z-index: 1001;
transform: translateX(100%);
transition: transform 0.3s ease;
box-shadow: var(--ecg-shadow-lg);
}

.ecg-toast.show {
transform: translateX(0);
}

.ecg-toast-success {
background: var(--ecg-success);
}

.ecg-toast-error {
background: var(--ecg-error);
}

/* Pro Tips */
.ecg-pro-tip {
background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
border: 1px solid #f59e0b;
border-radius: 0.75rem;
padding: 1rem;
margin: 1.5rem 0;
}

.ecg-pro-tip-title {
font-weight: 600;
color: #92400e;
margin-bottom: 0.5rem;
display: flex;
align-items: center;
gap: 0.5rem;
}

.ecg-pro-tip-text {
font-size: 0.875rem;
color: #92400e;
margin: 0;
}

/* Attendee Download Button Special Styling - GREEN GRADIENT */
#ecg-attendee-download {
font-size: 1.5rem !important;
padding: 1.5rem 2rem !important;
width: 100% !important;
margin-top: 1rem;
transition: all 0.3s ease;
border-radius: 1rem !important;
font-weight: 700 !important;
background: linear-gradient(135deg, #22c55e, #16a34a) !important;
color: white !important;
box-shadow: var(--ecg-shadow) !important;
}

#ecg-attendee-download:disabled {
background: #9ca3af !important;
cursor: not-allowed !important;
transform: none !important;
}

#ecg-attendee-download:not(:disabled):hover {
background: linear-gradient(135deg, #16a34a, #15803d) !important;
transform: translateY(-3px) !important;
box-shadow: 0 20px 25px -5px rgba(34, 197, 94, 0.3), 0 10px 10px -5px rgba(34, 197, 94, 0.2) !important;
}

.ecg-pulse {
animation: pulse-button 2s infinite;
}

@keyframes pulse-button {
0%, 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
}

/* Utility Classes */
.ecg-hidden {
display: none !important;
}

.ecg-flex {
display: flex;
}

.ecg-items-center {
align-items: center;
}

.ecg-justify-between {
justify-content: space-between;
}

.ecg-gap-2 {
gap: 0.5rem;
}

.ecg-mb-4 {
margin-bottom: 1rem;
}

.ecg-text-center {
text-align: center;
}

/* Hide file inputs */
input[type="file"] {
display: none;
}
CSS'
);
}
add_action('wp_enqueue_scripts', 'ecg_final_enqueue_scripts');

/**
* AJAX handler to save shareable cover data - FINAL VERSION WITH ENHANCED SECURITY
*/
function ecg_save_share_final() {
// Enhanced security checks
// Verify the nonce against the AJAX action. Using the same string that was
// passed to wp_create_nonce() during script localization ensures consistency.
if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ecg_save_share_final')) {
wp_send_json_error('Invalid security token');
}

// Rate limiting check
$user_ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
$rate_limit_key = 'ecg_rate_limit_' . md5($user_ip);
$requests = get_transient($rate_limit_key) ?: 0;

if ($requests >= 10) { // Max 10 requests per hour
wp_send_json_error('Rate limit exceeded. Please try again later.');
}

set_transient($rate_limit_key, $requests + 1, HOUR_IN_SECONDS);

// Sanitize and validate input data
$image = isset($_POST['image']) ? sanitize_textarea_field($_POST['image']) : '';
$placeholder = isset($_POST['placeholder']) ? json_decode(stripslashes($_POST['placeholder']), true) : array();
$eventDetails = isset($_POST['eventDetails']) ? json_decode(stripslashes($_POST['eventDetails']), true) : array();
$dimensions = isset($_POST['dimensions']) ? json_decode(stripslashes($_POST['dimensions']), true) : array();
$bgFitMode = isset($_POST['bgFitMode']) ? sanitize_text_field($_POST['bgFitMode']) : 'fill';

// Enhanced validation
if (empty($image) || !preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $image)) {
wp_send_json_error('Invalid image data');
}

if (empty($placeholder) || !is_array($placeholder)) {
wp_send_json_error('Invalid placeholder data');
}

if (empty($eventDetails['name']) || strlen($eventDetails['name']) > 100) {
wp_send_json_error('Invalid event name');
}

if (empty($eventDetails['endDate']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDetails['endDate'])) {
wp_send_json_error('Invalid event end date');
}

// Validate image size (base64 encoded, so roughly 1.33x original size)
$imageSize = strlen($image) * 0.75; // Approximate decoded size
if ($imageSize > 15 * 1024 * 1024) { // 15MB limit
wp_send_json_error('Image too large');
}

// Calculate expiration based on event end date
$expiration = 0;
if (!empty($eventDetails['endDate'])) {
// Use wp_timezone() if available (introduced in WP 5.3). Fallback to site timezone or UTC on older installs.
if (function_exists('wp_timezone')) {
$timezone = wp_timezone();
} else {
$timezone_string = get_option('timezone_string');
try {
$timezone = new DateTimeZone($timezone_string ? $timezone_string : 'UTC');
} catch (Exception $e) {
$timezone = new DateTimeZone('UTC');
}
}
$eventEndDate = date_create_from_format('Y-m-d', $eventDetails['endDate'], $timezone);

if ($eventEndDate) {
// Set to end of event day (23:59:59)
$eventEndDate->setTime(23, 59, 59);
$eventEndTimestamp = $eventEndDate->getTimestamp();
$currentTimestamp = time();

// Calculate seconds until event ends
$expiration = max(0, $eventEndTimestamp - $currentTimestamp);

// If event has already ended, set very short expiration (1 hour)
if ($expiration <= 0) {
$expiration = HOUR_IN_SECONDS;
}

error_log("ECG Final: Event ends on " . $eventDetails['endDate'] . ", expiration set to " . $expiration . " seconds");
}
}

// Fallback: if no valid end date, expire in 7 days
if ($expiration <= 0) {
$expiration = 7 * DAY_IN_SECONDS;
error_log("ECG Final: No valid end date, using 7-day fallback expiration");
}

// Generate secure token
$token = wp_generate_password(20, false, false);

// Prepare data with enhanced security
$data = array(
'image' => $image,
'placeholder' => $placeholder,
'eventDetails' => array(
'name' => sanitize_text_field($eventDetails['name']),
'endDate' => sanitize_text_field($eventDetails['endDate'])
),
'dimensions' => $dimensions,
'bgFitMode' => in_array($bgFitMode, ['fill', 'fit']) ? $bgFitMode : 'fill',
'version' => 3.21,
'created' => time(),
'expires' => time() + $expiration,
'creator_ip' => $user_ip,
'creator_user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
'security_hash' => wp_hash($image . $token) // Additional security layer
);

$result = set_transient('ecg_share_' . $token, $data, $expiration);

if ($result) {
wp_send_json_success(array(
'token' => $token,
'expires_on' => date('Y-m-d H:i:s', time() + $expiration),
'event_end_date' => $eventDetails['endDate'],
'notification_enabled' => true
));
} else {
wp_send_json_error('Failed to save share data');
}
}
add_action('wp_ajax_ecg_save_share_final', 'ecg_save_share_final');
add_action('wp_ajax_nopriv_ecg_save_share_final', 'ecg_save_share_final');

/**
* AJAX handler to fetch shared data for attendee links.
* Allows the front-end to retrieve the saved event data when the inline
* script (window.ecg_shared_data) is unavailable. This is especially
* useful if caching or script order prevents the injected data from
* being accessible when the JS initializes.
*/
function ecg_get_share_data() {
$token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
// Validate token format (20 alphanumeric characters)
if (!preg_match('/^[A-Za-z0-9]{20}$/', $token)) {
wp_send_json_error('Invalid token');
}
$sharedData = get_transient('ecg_share_' . $token);
if (!$sharedData || !is_array($sharedData)) {
wp_send_json_error('No data found or link expired');
}
// Validate security hash (only for data created with v3.2 or later). Older saved data
// may not include a security_hash, so gracefully skip the check in that case.
if (isset($sharedData['security_hash'])) {
// Compute expected hash and compare. If it doesn't match, reject the data.
if ($sharedData['security_hash'] !== wp_hash($sharedData['image'] . $token)) {
wp_send_json_error('Invalid data');
}
// Remove the hash before sending to client
unset($sharedData['security_hash']);
}
// Remove sensitive fields before sending
unset($sharedData['creator_ip'], $sharedData['creator_user_agent']);
wp_send_json_success($sharedData);
}
add_action('wp_ajax_ecg_get_share_data', 'ecg_get_share_data');
add_action('wp_ajax_nopriv_ecg_get_share_data', 'ecg_get_share_data');

/**
* Final shortcode callback with enhanced security and better attendee layout
*/
function event_cover_generator_final_shortcode($atts) {
$atts = shortcode_atts(array(
'title' => 'Event Cover Generator',
'subtitle' => 'Create stunning event covers with modern wizard interface'
), $atts);

ob_start();
?>
<div class="ecg-container">
<!-- Header -->
<div class="ecg-header">
<h1 class="ecg-title"><?php echo esc_html($atts['title']); ?></h1>
<p class="ecg-subtitle"><?php echo esc_html($atts['subtitle']); ?></p>
</div>

<!-- Stepper (Hidden in attendee mode) -->
<div class="ecg-stepper">
<div class="ecg-stepper-line"></div>
<div class="ecg-stepper-progress" id="ecg-stepper-progress"></div>

<div class="ecg-stepper-item active completed" data-step="1">
<div class="ecg-stepper-icon">1</div>
<div class="ecg-stepper-label">Event Setup</div>
</div>

<div class="ecg-stepper-item" data-step="2">
<div class="ecg-stepper-icon">2</div>
<div class="ecg-stepper-label">Background</div>
</div>

<div class="ecg-stepper-item" data-step="3">
<div class="ecg-stepper-icon">3</div>
<div class="ecg-stepper-label">Finalize</div>
</div>
</div>

<div class="ecg-main-grid">
<!-- Left Panel -->
<div class="ecg-form-panel">
<!-- History Controls -->
<div class="ecg-history" id="ecg-creator-controls">
<button id="ecg-undo" class="ecg-history-btn" title="Undo (Ctrl+Z)">↶</button>
<button id="ecg-redo" class="ecg-history-btn" title="Redo (Ctrl+Y)">↷</button>
</div>

<!-- Step 1: Event Setup -->
<div id="ecg-step-1" class="ecg-step-content active">
<div class="ecg-card">
<div class="ecg-card-header">
<h3 class="ecg-card-title">📅 Event Information</h3>
</div>
<div class="ecg-card-content">
<div class="ecg-form-group">
<label class="ecg-label" for="ecg-event-name">Event Name *</label>
<input type="text" id="ecg-event-name" class="ecg-input" placeholder="Enter your event name" maxlength="100">
</div>
<div class="ecg-form-group">
<label class="ecg-label" for="ecg-event-end-date">Event End Date *</label>
<input type="date" id="ecg-event-end-date" class="ecg-input">
</div>
</div>
</div>

<div class="ecg-card">
<div class="ecg-card-header">
<h3 class="ecg-card-title">📐 Cover Dimensions</h3>
</div>
<div class="ecg-card-content">
<div class="ecg-btn-group">
<button class="ecg-dimension-btn active" data-dimension="square">
<span>⬜</span>
<div>Square<br><small>1080×1080</small></div>
</button>
<button class="ecg-dimension-btn" data-dimension="portrait">
<span>📱</span>
<div>Portrait<br><small>1080×1350</small></div>
</button>
<button class="ecg-dimension-btn" data-dimension="landscape">
<span>🖥️</span>
<div>Landscape<br><small>1920×1080</small></div>
</button>
</div>
</div>
</div>

<div class="ecg-pro-tip">
<div class="ecg-pro-tip-title">💡 Pro Tip</div>
<p class="ecg-pro-tip-text">Choose dimensions based on where you'll share your cover. Square works great for social media, while landscape is perfect for event banners.</p>
</div>
</div>

<!-- Step 2: Background Design -->
<div id="ecg-step-2" class="ecg-step-content">
<div class="ecg-card">
<div class="ecg-card-header">
<h3 class="ecg-card-title">🖼️ Background Image</h3>
</div>
<div class="ecg-card-content">
<div class="ecg-upload" id="ecg-bg-upload">
<input type="file" id="ecg-bg-file" accept="image/jpeg,image/jpg,image/png,image/webp">
<div class="ecg-upload-icon">📤</div>
<div class="ecg-upload-text">Click to upload or drag & drop</div>
<div class="ecg-upload-subtext">JPG, PNG, or WebP up to 10MB</div>
</div>
</div>
</div>

<div class="ecg-card" id="ecg-fit-mode-card" style="display:none;">
<div class="ecg-card-header">
<h3 class="ecg-card-title">🎯 Background Fit</h3>
</div>
<div class="ecg-card-content">
<div class="ecg-btn-group">
<button class="ecg-fit-btn active" data-mode="fill">
<span>🔍</span>
<div>Fill<br><small>Cover canvas</small></div>
</button>
<button class="ecg-fit-btn" data-mode="fit">
<span>📏</span>
<div>Fit<br><small>Show all</small></div>
</button>
</div>
</div>
</div>

<div class="ecg-pro-tip">
<div class="ecg-pro-tip-title">🎨 Design Tips</div>
<p class="ecg-pro-tip-text">Use high-resolution images (at least 1080px) for best quality. Consider leaving space for the attendee photo placeholder.</p>
</div>
</div>

<!-- Step 3: Finalize -->
<div id="ecg-step-3" class="ecg-step-content">
    <div class="ecg-card">
        <div class="ecg-card-header">
            <h3 class="ecg-card-title">👤 Photo Placeholder Settings</h3>
        </div>
        <div class="ecg-card-content">
            <div class="ecg-btn-group">
                <button class="ecg-shape-btn active" data-shape="circle">
                    <span>⭕</span>
                    <div>Circle</div>
                </button>
                <button class="ecg-shape-btn" data-shape="square">
                    <span>⬜</span>
                    <div>Square</div>
                </button>
                <button class="ecg-shape-btn" data-shape="portrait">
                    <span>📱</span>
                    <div>Portrait</div>
                </button>
            </div>

            <div class="ecg-slider-container">
                <label id="ecg-size-label" class="ecg-slider-label">Placeholder Size: 50%</label>
                <input type="range" id="ecg-size-slider" class="ecg-slider" min="20" max="100" value="50">
            </div>
        </div>
    </div>

    <!-- Action Buttons Card -->
    <div class="ecg-card">
        <div class="ecg-card-header">
            <h3 class="ecg-card-title">🚀 Actions</h3>
        </div>
        <div class="ecg-card-content">
            <div class="ecg-final-actions">
                <button id="ecg-save" class="ecg-btn ecg-btn-outline">
                    💾 Save Cover
                </button>
                <button id="ecg-download" class="ecg-btn ecg-btn-secondary">
                    ⬇️ Download
                </button>
                <button id="ecg-share" class="ecg-btn ecg-btn-primary">
                    🔗 Generate Share Link
                </button>
            </div>
        </div>
    </div>

    <!-- Ready to Share Info -->
    <div class="ecg-pro-tip">
        <div class="ecg-pro-tip-title">🎉 Ready to Share!</div>
        <p class="ecg-pro-tip-text">Generate a share link to let attendees upload their photos and download personalized covers. You'll receive email notifications before the link expires!</p>
    </div>
</div>

<!-- FIXED ATTENDEE CONTROLS -->
<div id="ecg-attendee-controls" style="display: none;">
    <!-- Step 1: Upload Photo -->
    <div class="ecg-attendee-step">
        <div class="ecg-attendee-step-header">
            <div class="ecg-attendee-step-number">1</div>
            <div class="ecg-attendee-step-title">Upload Your Photo</div>
            <div class="ecg-attendee-step-description">Choose a high-quality photo to add to your personalized event cover</div>
        </div>
        <div class="ecg-attendee-step-content">
            <div class="ecg-attendee-upload-area" id="ecg-attendee-upload">
                <input type="file" id="ecg-attendee-file" accept="image/jpeg,image/jpg,image/png,image/webp">
                <div class="ecg-upload-icon">📷</div>
                <div class="ecg-upload-text">Click here to upload your photo</div>
                <div class="ecg-upload-subtext">JPG, PNG, or WebP up to 10MB • Best quality for clear photos</div>
            </div>
        </div>
    </div>

    <!-- Step 2: Preview (Shows after upload) -->
    <div class="ecg-attendee-step">
        <div class="ecg-attendee-step-header">
            <div class="ecg-attendee-step-number">2</div>
            <div class="ecg-attendee-step-title">Preview Your Cover</div>
            <div class="ecg-attendee-step-description">See how your photo looks in the event cover design</div>
        </div>
        <div class="ecg-attendee-step-content">
            <div class="ecg-preview-section">
                <div class="ecg-preview-title">👀 Your Personalized Cover Preview</div>
                <!-- Use unique ID for attendee event info to avoid duplicate IDs -->
                <div id="ecg-event-info-attendee" class="ecg-event-info"></div>
                <div class="ecg-canvas-container">
                    <!-- Use unique ID for attendee canvas to avoid duplicate IDs -->
                    <canvas id="ecg-canvas-attendee" class="ecg-canvas"></canvas>
                </div>
                <div class="ecg-text-center" style="margin-top: 1rem; font-size: 0.875rem; color: var(--ecg-text-light);">
                    <!-- Use unique ID for attendee canvas info to avoid duplicate IDs -->
                    <span id="ecg-canvas-info-attendee">Square • 1080 × 1080px</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 3: Download (Shows after upload) -->
    <div class="ecg-attendee-step" id="ecg-attendee-download-section" style="display: none;">
        <div class="ecg-attendee-step-header">
            <div class="ecg-attendee-step-number">3</div>
            <div class="ecg-attendee-step-title">Download Your Cover</div>
            <div class="ecg-attendee-step-description">Get your personalized event cover in high quality</div>
        </div>
        <div class="ecg-attendee-step-content">
            <button id="ecg-attendee-download" class="ecg-btn ecg-btn-primary" disabled>
                🎉 Download Your Personalized Cover
            </button>
            <p style="text-align: center; margin-top: 1rem; font-size: 1rem; color: var(--ecg-text-light);">
                High-quality PNG format • Perfect for social media sharing
            </p>

            <!-- Additional Instructions for Attendees -->
            <div class="ecg-pro-tip" style="margin-top: 1.5rem;">
                <div class="ecg-pro-tip-title">📱 Sharing Tips</div>
                <p class="ecg-pro-tip-text">Your personalized cover is ready! Share it on social media, use it as your profile picture, or print it for physical displays. The high-resolution format ensures it looks great everywhere.</p>
            </div>
        </div>
    </div>

    <!-- Attendee Ad Section -->
    <div class="ecg-ad-section ecg-attendee-ad">
        <div class="ecg-ad-header">
            🎯 Love This Tool?
        </div>
        <div class="ecg-ad-content">
            Create your own professional event covers and marketing materials! Get access to premium templates and design tools.
        </div>
        <a href="#" class="ecg-ad-cta" onclick="window.open('https://your-design-service.com', '_blank')">
            🎨 Try Premium Tools
        </a>
    </div>
</div>

<!-- Navigation -->
<div class="ecg-navigation">
<button id="ecg-prev-btn" class="ecg-btn ecg-btn-outline ecg-hidden">← Previous</button>
<button id="ecg-next-btn" class="ecg-btn ecg-btn-primary">Next →</button>
</div>
</div>

<!-- Right Panel: Canvas (Hidden in attendee mode) -->
<div class="ecg-preview-panel">
<div class="ecg-card">
<div class="ecg-card-header">
<h3 class="ecg-card-title">👁️ Live Preview</h3>
</div>
<div class="ecg-card-content">
<div id="ecg-event-info"></div>
<div class="ecg-canvas-container">
<canvas id="ecg-canvas" class="ecg-canvas"></canvas>
<div class="ecg-canvas-hint">Drag placeholder to reposition</div>
</div>
<div class="ecg-text-center" style="margin-top: 1rem; font-size: 0.875rem; color: var(--ecg-text-light);">
<span id="ecg-canvas-info">Square • 1080 × 1080px</span>
</div>
</div>
</div>
</div>
</div>
</div>

<!-- Share Modal -->
<div id="ecg-share-modal" class="ecg-modal">
<div class="ecg-modal-content">
<button id="ecg-close-modal" class="ecg-modal-close">×</button>
<h3 class="ecg-modal-title">🔗 Share Your Cover Generator</h3>
<p>Share this link with attendees so they can upload their photos and download personalized covers. <strong>You'll receive email notifications before the link expires!</strong></p>

<div class="ecg-link-container">
<input type="text" id="ecg-share-link" class="ecg-link-input" readonly>
<button id="ecg-copy-link" class="ecg-btn ecg-btn-primary">📋 Copy</button>
</div>

<div class="ecg-pro-tip">
<div class="ecg-pro-tip-title">📧 Email Notifications</div>
<p class="ecg-pro-tip-text">You'll receive an email reminder 24 hours before your share link expires. The system also automatically cleans up expired data to keep your site running smoothly!</p>
</div>
</div>
</div>

<?php
// Check for shared data and inject it into the page with enhanced security
if (isset($_GET['ecg_token'])) {
    $token = sanitize_text_field($_GET['ecg_token']);
    // Validate token format
    if (preg_match('/^[a-zA-Z0-9]{20}$/', $token)) {
        $sharedData = get_transient('ecg_share_' . $token);
        if ($sharedData && is_array($sharedData)) {
            $valid = true;
            // If a security_hash is present (from v3.2 and later), verify it. Older data
            // will simply skip this check and be considered valid.
            if (isset($sharedData['security_hash'])) {
                if ($sharedData['security_hash'] !== wp_hash($sharedData['image'] . $token)) {
                    $valid = false;
                }
                unset($sharedData['security_hash']);
            }
            if ($valid) {
                // Remove sensitive data
                unset($sharedData['creator_ip']);
                unset($sharedData['creator_user_agent']);
                echo '<script>window.ecg_shared_data = ' . wp_json_encode($sharedData) . ';</script>';
            } else {
                echo '<script>console.log("ECG Final: Security validation failed for token");</script>';
            }
        } else {
            // No shared data was found for this token. Log a clear message for debugging.
            echo '<script>console.log("ECG Final: No shared data found for token: ' . esc_js($token) . ' - link may have expired");</script>';
        }
    } else {
        echo '<script>console.log("ECG Final: Invalid token format");</script>';
    }
}

return ob_get_clean();
}
add_shortcode('event_cover_generator_final', 'event_cover_generator_final_shortcode');
