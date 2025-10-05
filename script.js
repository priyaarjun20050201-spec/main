document.addEventListener('DOMContentLoaded', () => {
    const speakBtn = document.getElementById('speak-btn');
    const textInput = document.getElementById('text-input');

    // This is the realistic, albeit hypothetical, API endpoint for YarnGPT.
    const YARNGPT_API_ENDPOINT = 'https://api.yarngpt.co/v1/tts';

    speakBtn.addEventListener('click', async () => {
        const text = textInput.value.trim();
        if (text === '') {
            alert('Please enter some text to speak.');
            return;
        }

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

            playAudio(audioUrl);

        } catch (error) {
            console.error('TTS Error:', error);
            // Since this is a hypothetical API, we'll fall back to the mock for demonstration.
            alert("This is a demo. Playing a sample Nigerian-accented audio.");
            playAudio('https://upload.wikimedia.org/wikipedia/commons/2/21/En-us-Nigeria.ogg');
        }
    });

    function playAudio(audioUrl) {
        const audio = new Audio(audioUrl);

        audio.onplay = () => {
            speakBtn.textContent = 'Playing...';
            speakBtn.disabled = true;
        };

        audio.onended = () => {
            speakBtn.textContent = 'Speak';
            speakBtn.disabled = false;
        };

        audio.onerror = () => {
            alert('Could not play the audio file.');
            speakBtn.textContent = 'Speak';
            speakBtn.disabled = false;
        };

        audio.play();
    }
});