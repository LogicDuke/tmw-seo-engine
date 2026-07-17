'use strict';
/** Browser-compatible extraction function: pass it directly to page.evaluate. */
function extractProfile(username) {
  const text = (value) => typeof value === 'string' ? value.replace(/\s+/g, ' ').trim() : '';
  const found = [];
  const select = (selectors, key) => { for (const selector of selectors) { const node = document.querySelector(selector); const value = text(node?.textContent); if (value) { found.push(key); return value; } } return ''; };
  const labelled = (label, key) => { const nodes = [...document.querySelectorAll('dt,th,label,h2,h3,h4')]; const node = nodes.find((item) => text(item.textContent).toLowerCase() === label.toLowerCase()); const value = text((node?.nextElementSibling || node?.parentElement?.querySelector('dd,td,p,span'))?.textContent); if (value) found.push(key); return value; };
  const heading = select(['[data-testid="profile-username"]', 'h1', '[role="heading"]'], 'username');
  if (heading && !heading.toLowerCase().includes(username.toLowerCase())) return { conflict: true, fields: {}, found };
  const image = document.querySelector('[data-testid="profile-image"], .profile-image img');
  const tags = [...document.querySelectorAll('[data-testid="profile-tag"], .profile-tag')].map((node) => text(node.textContent)).filter(Boolean);
  if (tags.length) found.push('tags');
  const number = (value, key) => { const match = text(value).match(/\d+(?:[.,]\d+)?/); if (!match) return null; found.push(key); return Number(match[0].replace(',', '.')); };
  const fields = { display_name: select(['[data-testid="profile-name"]', 'h1'], 'display_name'), raw_fields: { bio: select(['[data-testid="profile-bio"]', '.profile-bio', '[aria-label="About"]'], 'bio') || labelled('About', 'bio'), schedule: labelled('Schedule', 'schedule'), tags }, attributes: {} };
  const a = fields.attributes;
  a.age = number(labelled('Age', 'age'), 'age'); a.country = labelled('Country', 'country'); const languages = labelled('Languages', 'languages'); a.languages = languages ? languages.split(',').map(text).filter(Boolean) : []; if (a.languages.length && !found.includes('languages')) found.push('languages'); a.height_cm = number(labelled('Height', 'height_cm'), 'height_cm'); a.body_type = labelled('Body type', 'body_type') || labelled('Build', 'body_type'); a.hair_color = labelled('Hair color', 'hair_color'); a.eye_color = labelled('Eye color', 'eye_color'); a.orientation = labelled('Orientation', 'orientation'); a.rating = number(labelled('Rating', 'rating'), 'rating'); a.rating_count = number(labelled('Rating count', 'rating_count'), 'rating_count'); a.profile_image_url = text(image?.src || ''); if (a.profile_image_url) found.push('profile_image_url');
  return { conflict: false, fields, found: [...new Set(found)].filter((key) => key !== 'username') };
}
module.exports = { extractProfile };
