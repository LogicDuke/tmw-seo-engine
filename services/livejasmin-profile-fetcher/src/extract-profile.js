'use strict';
/** Browser-compatible extraction function: pass it directly to page.evaluate. */
function extractProfile(username) {
  const text = (value) => typeof value === 'string' ? value.replace(/\s+/g, ' ').trim() : '';
  const select = (selectors) => {
    for (const selector of selectors) {
      const value = text(document.querySelector(selector)?.textContent);
      if (value) return value;
    }
    return '';
  };
  const labelled = (label) => {
    const node = [...document.querySelectorAll('dt,th,label,h2,h3,h4')]
      .find((item) => text(item.textContent).toLowerCase() === label.toLowerCase());
    return text((node?.nextElementSibling || node?.parentElement?.querySelector('dd,td,p,span'))?.textContent);
  };
  const number = (value) => {
    const match = text(value).match(/\d+(?:[.,]\d+)?/);
    if (!match) return null;
    const result = Number(match[0].replace(',', '.'));
    return Number.isFinite(result) ? result : null;
  };
  const heading = select(['[data-testid="profile-username"]', 'h1', '[role="heading"]']);
  if (heading && !heading.toLowerCase().includes(username.toLowerCase())) return { conflict: true, fields: {}, found: [] };
  const languages = labelled('Languages').split(',').map(text).filter(Boolean);
  const tags = [...document.querySelectorAll('[data-testid="profile-tag"], .profile-tag')].map((node) => text(node.textContent)).filter(Boolean);
  const image = text(document.querySelector('[data-testid="profile-image"], .profile-image img')?.src || '');
  const fields = {
    display_name: select(['[data-testid="profile-name"]', 'h1']),
    raw_fields: { bio: select(['[data-testid="profile-bio"]', '.profile-bio', '[aria-label="About"]']) || labelled('About'), schedule: labelled('Schedule'), tags },
    attributes: { age: number(labelled('Age')), country: labelled('Country'), languages, height_cm: number(labelled('Height')), body_type: labelled('Body type') || labelled('Build'), hair_color: labelled('Hair color'), eye_color: labelled('Eye color'), orientation: labelled('Orientation'), rating: number(labelled('Rating')), rating_count: number(labelled('Rating count')), profile_image_url: image },
  };
  const populated = (value) => typeof value === 'string' ? value !== '' : Array.isArray(value) ? value.length > 0 : typeof value === 'number' ? Number.isFinite(value) : false;
  const candidates = { display_name: fields.display_name, bio: fields.raw_fields.bio, schedule: fields.raw_fields.schedule, tags: fields.raw_fields.tags, ...fields.attributes };
  return { conflict: false, fields, found: Object.keys(candidates).filter((key) => populated(candidates[key])) };
}
module.exports = { extractProfile };
