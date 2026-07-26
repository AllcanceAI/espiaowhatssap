const https = require('https');

// Simple token cache in memory (works within serverless instance lifecycle)
let tokenCache = {
    token: null,
    expiresAt: 0
};

const CLIENT_ID = 'cli_0a848b556b07cf8949b10e3a2a9b91c8';
const CLIENT_SECRET = 'qDoNQNv6rdHMmvHJMjciPGjaaImefkbjCqH-t3XulKBozlzIoQbNEFvNR-O1aTH8zwygNHwS4ecdbiflpDaiHa83JobuLW04JG0F';

const products = {
    'full': 25.90,
    'discount': 25.90
};

function makeRequest(url, method, headers, body = null) {
    return new Promise((resolve, reject) => {
        try {
            const urlObj = new URL(url);
            const options = {
                hostname: urlObj.hostname,
                port: 443,
                path: urlObj.pathname + urlObj.search,
                method: method,
                headers: {
                    ...headers
                }
            };

            let postData = '';
            if (body) {
                postData = JSON.stringify(body);
                options.headers['Content-Length'] = Buffer.byteLength(postData);
            }

            const req = https.request(options, (res) => {
                let data = '';
                res.on('data', (chunk) => {
                    data += chunk;
                });
                res.on('end', () => {
                    resolve({
                        status: res.statusCode,
                        headers: res.headers,
                        body: data
                    });
                });
            });

            req.on('error', (err) => {
                reject(err);
            });

            if (body) {
                req.write(postData);
            }
            req.end();
        } catch (e) {
            reject(e);
        }
    });
}

async function getVeoPagToken() {
    const now = Math.floor(Date.now() / 1000);
    if (tokenCache.token && tokenCache.expiresAt > now) {
        return tokenCache.token;
    }

    try {
        const response = await makeRequest('https://api.veopag.com/api/auth/login', 'POST', {
            'Content-Type': 'application/json',
            'accept': 'application/json'
        }, {
            client_id: CLIENT_ID,
            client_secret: CLIENT_SECRET
        });

        if (response.status === 200) {
            const data = JSON.parse(response.body);
            if (data.token) {
                tokenCache = {
                    token: data.token,
                    expiresAt: now + 2700 // Cache for 45 minutes
                };
                return data.token;
            }
        }
    } catch (err) {
        console.error('Error fetching token:', err);
    }
    return null;
}

module.exports = async function handler(req, res) {
    // Enable CORS
    res.setHeader('Access-Control-Allow-Credentials', true);
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET,OPTIONS,PATCH,DELETE,POST,PUT');
    res.setHeader(
        'Access-Control-Allow-Headers',
        'X-CSRF-Token, X-Requested-With, Accept, Accept-Version, Content-Length, Content-MD5, Content-Type, Date, X-Api-Version'
    );

    if (req.method === 'OPTIONS') {
        res.status(200).end();
        return;
    }

    const { action } = req.query;

    if (action === 'create' && req.method === 'POST') {
        try {
            const body = req.body || {};
            const productKey = body.product || 'full';
            const name = body.name || 'Cliente Pix';
            const document = (body.document || '').replace(/\D/g, '');

            if (!document || document.length < 11) {
                return res.status(400).json({
                    success: false,
                    message: 'CPF válido é obrigatório.'
                });
            }

            const amount = products[productKey] || 25.90;
            const token = await getVeoPagToken();

            if (!token) {
                return res.status(500).json({
                    success: false,
                    message: 'Erro de autenticação com o gateway. Verifique as credenciais.'
                });
            }

            const externalId = 'espiao_' + Math.random().toString(36).substring(2, 9) + '_' + Date.now();

            const payload = {
                amount: amount,
                external_id: externalId,
                payer: {
                    name: name,
                    document: document
                },
                utm_source: body.utm_source || '',
                utm_medium: body.utm_medium || '',
                utm_campaign: body.utm_campaign || '',
                utm_content: body.utm_content || '',
                utm_term: body.utm_term || '',
                src: body.src || '',
                sck: body.sck || ''
            };

            const response = await makeRequest('https://api.veopag.com/api/payments/deposit', 'POST', {
                'Content-Type': 'application/json',
                'accept': 'application/json',
                'Authorization': 'Bearer ' + token
            }, payload);

            let data = {};
            try {
                data = JSON.parse(response.body);
            } catch (e) {}

            if (response.status === 200 || response.status === 201) {
                const pixData = data.qrCodeResponse || data;
                if (pixData.transactionId && pixData.qrcode) {
                    const qrCodeText = pixData.qrcode;
                    const qrCodeImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(qrCodeText);

                    return res.status(200).json({
                        success: true,
                        transactionId: pixData.transactionId,
                        qrcode: qrCodeText,
                        qr_code_image: qrCodeImageUrl,
                        amount: amount
                    });
                }
            }

            return res.status(400).json({
                success: false,
                message: 'Erro ao gerar o PIX junto à VeoPag.',
                details: data
            });

        } catch (err) {
            console.error('Error creating deposit:', err);
            return res.status(500).json({
                success: false,
                message: 'Erro interno ao processar a requisição.',
                error: err.message,
                stack: err.stack
            });
        }
    }

    if (action === 'status') {
        try {
            const { transactionId } = req.query;

            if (!transactionId) {
                return res.status(400).json({
                    success: false,
                    message: 'ID da transação é obrigatório.'
                });
            }

            const token = await getVeoPagToken();
            if (!token) {
                return res.status(500).json({
                    success: false,
                    message: 'Erro de autenticação com o gateway.'
                });
            }

            const response = await makeRequest('https://api.veopag.com/api/transactions/deposit?transaction_id=' + encodeURIComponent(transactionId), 'GET', {
                'accept': 'application/json',
                'Authorization': 'Bearer ' + token
            });

            let data = {};
            try {
                data = JSON.parse(response.body);
            } catch (e) {}

            if (response.status === 200) {
                let status = 'waiting_payment';
                if (data.status) {
                    status = data.status;
                } else if (Array.isArray(data) && data[0] && data[0].status) {
                    status = data[0].status;
                } else if (data.data && data.data.status) {
                    status = data.data.status;
                } else if (Array.isArray(data.data) && data.data[0] && data.data[0].status) {
                    status = data.data[0].status;
                }

                const isPaid = (status.toUpperCase() === 'PAID' || status.toUpperCase() === 'COMPLETED');

                return res.status(200).json({
                    success: true,
                    status: status,
                    paid: isPaid
                });
            }

            return res.status(400).json({
                success: false,
                message: 'Erro ao consultar status da transação.'
            });

        } catch (err) {
            console.error('Error checking status:', err);
            return res.status(500).json({
                success: false,
                message: 'Erro interno ao consultar status.'
            });
        }
    }

    return res.status(400).json({
        success: false,
        message: 'Ação ou método inválido.'
    });
}
