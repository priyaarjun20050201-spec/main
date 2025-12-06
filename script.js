document.addEventListener('DOMContentLoaded', () => {
    const urlInput = document.getElementById('url-input');
    const shortenBtn = document.getElementById('shorten-btn');
    const resultContainer = document.getElementById('result-container');
    const originalLinkSpan = document.getElementById('original-link');
    const shortLinkAnchor = document.getElementById('short-link');
    const copyBtn = document.getElementById('copy-btn');
    const errorMessage = document.getElementById('error-message');

    shortenBtn.addEventListener('click', shortenUrl);

    // Allow pressing Enter to submit
    urlInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            shortenUrl();
        }
    });

    copyBtn.addEventListener('click', () => {
        const shortUrl = shortLinkAnchor.href;
        navigator.clipboard.writeText(shortUrl).then(() => {
            copyBtn.textContent = 'Copied!';
            copyBtn.classList.add('copied');
            setTimeout(() => {
                copyBtn.textContent = 'Copy';
                copyBtn.classList.remove('copied');
            }, 2000);
        }).catch(err => {
            console.error('Failed to copy: ', err);
        });
    });

    async function shortenUrl() {
        const originalUrl = urlInput.value.trim();

        // Reset UI
        errorMessage.classList.add('hidden');
        urlInput.classList.remove('error');
        resultContainer.classList.add('hidden');

        if (!originalUrl) {
            showError('Please add a link');
            return;
        }

        try {
            const response = await fetch('/api/shorten', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ originalUrl })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Something went wrong');
            }

            displayResult(originalUrl, data.shortUrl);
        } catch (error) {
            showError(error.message);
        }
    }

    function displayResult(original, short) {
        originalLinkSpan.textContent = original;
        shortLinkAnchor.textContent = short;
        shortLinkAnchor.href = short;
        resultContainer.classList.remove('hidden');
    }

    function showError(message) {
        errorMessage.textContent = message;
        errorMessage.classList.remove('hidden');
        urlInput.classList.add('error');
    }
});
