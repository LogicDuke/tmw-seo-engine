'use strict';
const HOSTS = new Set(['livejasmin.com', 'www.livejasmin.com']);
const PATH = /^\/en\/chat\/([A-Za-z0-9][A-Za-z0-9_-]{0,63})$/;
function validateRequest(body) { if (!body || body.provider !== 'livejasmin' || typeof body.username !== 'string' || typeof body.source_url !== 'string') return {ok:false,message:'Invalid LiveJasmin fetch request.'}; let u; try { u=new URL(body.source_url); } catch { return {ok:false,message:'Invalid source URL.'}; } const m=PATH.exec(u.pathname); if(u.protocol!=='https:' || !HOSTS.has(u.hostname.toLowerCase()) || u.port || u.search || u.hash || !m || m[1].toLowerCase() !== body.username.toLowerCase()) return {ok:false,message:'Source URL must be the canonical HTTPS LiveJasmin chat URL and match username.'}; return {ok:true,url:u,username:m[1]}; }
function approvedUrl(url, username) { return validateRequest({provider:'livejasmin',source_url:url,username}).ok; }
module.exports={validateRequest,approvedUrl};
