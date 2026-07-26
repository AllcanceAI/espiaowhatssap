export default async function handler(req, res) {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Content-Type', 'application/json');

    const { phone } = req.query;

    if (!phone) {
        return res.status(400).json({ success: false, error: 'Phone number required' });
    }

    try {
        const response = await fetch('https://appespiao.online/pt/api.php?phone=' + encodeURIComponent(phone));
        const data = await response.json();
        return res.status(200).json(data);
    } catch (err) {
        return res.status(500).json({ success: false, error: err.message });
    }
}
