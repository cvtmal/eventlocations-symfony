// The sprite's hashed URL, from <meta name="admin-sprite"> in templates/admin/base.html.twig.
let url = null;

export function spriteUrl() {
    url ??= document.querySelector('meta[name="admin-sprite"]')?.content ?? '';

    return url;
}
