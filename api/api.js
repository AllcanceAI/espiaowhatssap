const https = require('https');

module.exports = async function handler(req, res) {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Content-Type', 'application/json');

    const { phone } = req.query;

    if (!phone) {
        return res.status(400).json({ success: false, error: 'Phone number required' });
    }

    try {
        const url = 'https://appespiao.online/pt/api.php?phone=' + encodeURIComponent(phone);
        
        const fetchPromise = () => new Promise((resolve, reject) => {
            https.get(url, (response) => {
                let data = '';
                response.on('data', (chunk) => {
                    data += chunk;
                });
                response.on('end', () => {
                    try {
                        resolve(JSON.parse(data));
                    } catch (e) {
                        resolve({ success: false, error: 'Invalid JSON response' });
                    }
                });
            }).on('error', (err) => {
                reject(err);
            });
        });

        const data = await fetchPromise();
        return res.status(200).json(data);
    } catch (err) {
        return res.status(500).json({ success: false, error: err.message });
    }
}
