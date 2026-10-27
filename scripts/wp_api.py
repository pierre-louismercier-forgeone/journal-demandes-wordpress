#!/usr/bin/env python3
"""Client WordPress du skill Journal des demandes. Aucune dépendance externe.

Le mot de passe d'application est lu dans ~/.config/journal-demandes/<domaine>.env
et n'est jamais affiché.

Commandes :
  init      --site URL                 crée le fichier d'identifiants à remplir
  check     --site URL                 vérifie la connexion et les droits
  detect    --site URL [--pages /a/ /b/]  lecture publique : formulaires, consentement, extensions visibles
  plugins   --site URL                 extensions installées (nom, version, état)
  types     --site URL                 types de contenu exposés à l'API
  status    --site URL                 état de l'extension Journal des demandes
  activate  --site URL --yes           active l'extension (après accord explicite)
  deactivate --site URL --yes          désactive l'extension (retour arrière)
  verify-page --site URL --path /contact/   vérifie que le script est servi aux visiteurs
  entries   --site URL [--limit 10]    dernières demandes du journal (extension active)
"""
import argparse, base64, json, os, re, stat, sys, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path

CONF_DIR = Path.home() / ".config" / "journal-demandes"
PLUGIN = "journal-demandes/journal-demandes"
UA = "JournalDemandesSkill/1.0"

FORMS = {
    "cf7": ["wpcf7", "/plugins/contact-form-7/"],
    "wpforms": ["wpforms-form", "/plugins/wpforms"],
    "gravity": ["gform_wrapper", "/plugins/gravityforms/"],
    "elementor": ["elementor-form"],
    "fluent": ["fluentform", "ff-el-group"],
    "formidable": ["frm_forms", "/plugins/formidable/"],
    "ninja": ["nf-form-cont", "/plugins/ninja-forms/"],
}
CONSENT = {
    "wp_consent_api": ["/plugins/wp-consent-api/", "wp-consent-api"],
    "complianz": ["complianz", "cmplz"],
    "cookieyes": ["cdn-cookieyes.com", "cookie-law-info", "cookieyes"],
    "cookiebot": ["consent.cookiebot.com", "cookiebot"],
    "axeptio": ["axept.io", "axeptioSettings"],
    "tarteaucitron": ["tarteaucitron"],
    "real_cookie_banner": ["real-cookie-banner"],
    "borlabs": ["borlabs-cookie"],
    "gdpr_cookie_compliance": ["gdpr-cookie-compliance", "moove_gdpr"],
    "iubenda": ["iubenda"],
    "didomi": ["didomi"],
    "onetrust": ["onetrust", "optanon"],
}


def host_of(site):
    return urllib.parse.urlparse(site if "://" in site else "https://" + site).hostname or ""


def base(site):
    p = urllib.parse.urlparse(site if "://" in site else "https://" + site)
    return p.scheme + "://" + p.netloc


def conf_path(site):
    return CONF_DIR / (host_of(site) + ".env")


def creds(site):
    path = conf_path(site)
    if not path.exists():
        sys.exit("Fichier d'identifiants absent : " + str(path) + ". Lancer d'abord la commande init.")
    data = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        if "=" in line and not line.strip().startswith("#"):
            k, v = line.split("=", 1)
            data[k.strip()] = v.strip().strip('"').strip("'")
    user, pwd = data.get("WP_USER", ""), data.get("WP_APP_PASSWORD", "")
    if not user or not pwd:
        sys.exit("Identifiant ou mot de passe d'application vide dans " + str(path))
    return user, pwd


def request(site, path, method="GET", body=None, auth=True, raw=False):
    url = path if path.startswith("http") else base(site) + path
    headers = {"User-Agent": UA, "Accept": "application/json" if not raw else "text/html"}
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    if auth:
        user, pwd = creds(site)
        headers["Authorization"] = "Basic " + base64.b64encode((user + ":" + pwd).encode()).decode()
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            text = r.read().decode("utf-8", "replace")
            return r.status, (text if raw else (json.loads(text) if text.strip() else {}))
    except urllib.error.HTTPError as e:
        text = e.read().decode("utf-8", "replace")
        try:
            return e.code, json.loads(text)
        except ValueError:
            return e.code, {"message": text[:300]}
    except urllib.error.URLError as e:
        return 0, {"message": str(e.reason)}


def out(obj):
    print(json.dumps(obj, ensure_ascii=False, indent=2))


def cmd_init(a):
    CONF_DIR.mkdir(parents=True, exist_ok=True)
    path = conf_path(a.site)
    if not path.exists():
        path.write_text("# Identifiants WordPress pour " + host_of(a.site) + "\n# Coller l'identifiant de connexion et le mot de passe d'application, puis enregistrer.\nWP_SITE=" + base(a.site) + "\nWP_USER=\nWP_APP_PASSWORD=\n", encoding="utf-8")
    os.chmod(path, stat.S_IRUSR | stat.S_IWUSR)
    out({"fichier": str(path), "droits": "lecture et écriture réservées à votre compte"})


def cmd_check(a):
    code, idx = request(a.site, "/wp-json/", auth=False)
    if code != 200:
        out({"api_publique": False, "code": code, "message": idx.get("message", "") if isinstance(idx, dict) else ""})
        return
    code, me = request(a.site, "/wp-json/wp/v2/users/me?context=edit")
    if code != 200:
        out({"api_publique": True, "connexion": False, "code": code, "message": me.get("message", ""), "piste": "401 : identifiant ou mot de passe faux, en tête Authorization retiré par l'hébergeur, ou mots de passe d'application désactivés par une extension de sécurité."})
        return
    caps = me.get("capabilities", {}) or {}
    out({"api_publique": True, "connexion": True, "utilisateur": me.get("name"), "roles": me.get("roles"),
         "droits": {k: bool(caps.get(k)) for k in ("manage_options", "activate_plugins", "install_plugins", "upload_plugins")},
         "site": idx.get("name"), "https": base(a.site).startswith("https")})


def scan(html, table):
    low = html.lower()
    return sorted(k for k, sigs in table.items() if any(s.lower() in low for s in sigs))


def cmd_detect(a):
    pages = ["/"] + (a.pages or [])
    result = {"pages": {}, "extensions_visibles": set(), "formulaires": set(), "consentement": set()}
    for p in pages:
        code, html = request(a.site, base(a.site) + p, auth=False, raw=True)
        html = html if isinstance(html, str) else ""
        f, c = scan(html, FORMS), scan(html, CONSENT)
        slugs = set(re.findall(r"/wp-content/plugins/([a-z0-9_\-]+)/", html, re.I))
        result["pages"][p] = {"code": code, "formulaires": f, "consentement": c}
        result["formulaires"].update(f); result["consentement"].update(c); result["extensions_visibles"].update(slugs)
    code, idx = request(a.site, "/wp-json/", auth=False)
    spaces = idx.get("namespaces", []) if code == 200 and isinstance(idx, dict) else []
    result["espaces_api"] = spaces or "API publique indisponible (" + str(code) + ")"
    known = {"contact-form-7/": "cf7", "wpforms/": "wpforms", "gf/": "gravity", "elementor-pro/": "elementor", "fluentform/": "fluent", "frm/": "formidable", "ninja-forms": "ninja"}
    result["indices_formulaires_api"] = sorted({v for s in spaces for k, v in known.items() if s.startswith(k)})
    standard = ("wp/", "oembed/", "wp-", "yoast", "litespeed", "wordfence", "akismet", "elementor", "google-site-kit", "redirection", "duplicator", "jetpack", "wc/", "mcp")
    result["espaces_maison_a_examiner"] = sorted(s for s in spaces if not s.startswith(standard) and not any(s.startswith(k) for k in known))
    for k in ("extensions_visibles", "formulaires", "consentement"):
        result[k] = sorted(result[k])
    result["avertissement"] = "Lecture publique seulement : une extension active peut rester invisible ici. Confirmer avec la commande plugins."
    out(result)


def cmd_plugins(a):
    code, data = request(a.site, "/wp-json/wp/v2/plugins")
    if code != 200:
        out({"code": code, "message": data.get("message", "") if isinstance(data, dict) else ""}); return
    out([{"nom": p.get("name"), "id": p.get("plugin"), "version": p.get("version"), "etat": p.get("status")} for p in data])


def cmd_types(a):
    code, data = request(a.site, "/wp-json/wp/v2/types?context=edit")
    if code != 200:
        out({"code": code, "message": data.get("message", "")}); return
    out({k: v.get("name") for k, v in data.items()})


def cmd_status(a):
    code, data = request(a.site, "/wp-json/wp/v2/plugins/" + PLUGIN)
    if code == 404:
        out({"installee": False}); return
    out({"installee": code == 200, "code": code, "version": data.get("version"), "etat": data.get("status")})


def toggle(a, status):
    if not a.yes:
        sys.exit("Refus : ajouter --yes uniquement après l'accord explicite du propriétaire du site.")
    code, data = request(a.site, "/wp-json/wp/v2/plugins/" + PLUGIN, method="POST", body={"status": status})
    out({"code": code, "etat": data.get("status"), "message": data.get("message", "")})


def cmd_verify_page(a):
    url = base(a.site) + a.path + ("&" if "?" in a.path else "?") + "jd_verif=" + str(int(time.time()))
    code, html = request(a.site, url, auth=False, raw=True)
    html = html if isinstance(html, str) else ""
    m = re.search(r"journal-demandes/assets/attribution\.js\?ver=([0-9.]+)", html)
    out({"url": url, "code": code, "script_present": bool(m), "version": m.group(1) if m else None, "configuration_present": "window.JDJournal" in html,
         "remarque": "Page lue en visiteur déconnecté. Si le script manque alors que l'extension est active, vider le cache de page."})


def cmd_entries(a):
    code, data = request(a.site, "/wp-json/journal-demandes/v1/demandes?limit=" + str(a.limit))
    out({"code": code, "demandes": data})


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("command", choices=["init", "check", "detect", "plugins", "types", "status", "activate", "deactivate", "verify-page", "entries"])
    ap.add_argument("--site", required=True)
    ap.add_argument("--pages", nargs="*")
    ap.add_argument("--path", default="/")
    ap.add_argument("--limit", type=int, default=10)
    ap.add_argument("--yes", action="store_true")
    a = ap.parse_args()
    {"init": cmd_init, "check": cmd_check, "detect": cmd_detect, "plugins": cmd_plugins, "types": cmd_types, "status": cmd_status,
     "activate": lambda x: toggle(x, "active"), "deactivate": lambda x: toggle(x, "inactive"),
     "verify-page": cmd_verify_page, "entries": cmd_entries}[a.command](a)


if __name__ == "__main__":
    main()
