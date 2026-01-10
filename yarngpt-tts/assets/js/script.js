document.addEventListener('DOMContentLoaded', () => {
    const speakBtn = document.getElementById('yarngpt-speak-btn');
    const textInput = document.getElementById('yarngpt-text-input');

    if (!speakBtn || !textInput) {
        return; // Elements not found, probably not on the page or shortcode not rendered
    }

    // This is the realistic, albeit hypothetical, API endpoint for YarnGPT.
    const YARNGPT_API_ENDPOINT = 'https://api.yarngpt.co/v1/tts';

    speakBtn.addEventListener('click', async () => {
        const text = textInput.value.trim();
        if (text === '') {
            alert('Please enter some text to speak.');
            return;
        }

        const originalText = speakBtn.textContent;
        speakBtn.textContent = 'Generating...';
        speakBtn.disabled = true;

        try {
            const response = await fetch(YARNGPT_API_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    text: text,
                    voice: 'idera', // A default female voice from YarnGPT
                    lang: 'english',
                }),
            });

            if (!response.ok) {
                throw new Error(`API request failed with status ${response.status}. Please try again.`);
            }

            const result = await response.json();
            const audioUrl = result.audio_url;

            if (!audioUrl) {
                throw new Error('API did not return an audio URL.');
            }

            playAudio(audioUrl, speakBtn, originalText);

        } catch (error) {
            console.error('TTS Error:', error);
            // Since this is a hypothetical API, we'll fall back to the mock for demonstration.
            alert("This is a demo. Playing a sample Nigerian-accented audio.");
            playAudio('https://upload.wikimedia.org/wikipedia/commons/2/21/En-us-Nigeria.ogg', speakBtn, originalText);
        }
    });

    function playAudio(audioUrl, button, originalText) {
        const audio = new Audio(audioUrl);

        audio.onplay = () => {
            button.textContent = 'Playing...';
            button.disabled = true;
        };

        // Reset button state when audio ends or errors
        const resetButton = () => {
            button.textContent = originalText || 'Speak';
            button.disabled = false;
        };

        audio.onended = resetButton;
        audio.onerror = () => {
            alert('Could not play the audio file.');
            resetButton();
        };

        audio.play().catch(e => {
            console.error("Audio playback failed:", e);
            resetButton();
        });
    }
});
