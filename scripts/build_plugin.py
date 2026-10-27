#!/usr/bin/env python3
"""Génère l'extension Journal des demandes à partir d'un fichier de configuration JSON.

Usage : python3 build_plugin.py --config config.json --out DOSSIER
Sortie : DOSSIER/journal-demandes.zip et DOSSIER/journal-demandes/ (copie lisible).
"""
import argparse, json, re, shutil, sys, zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
TEMPLATE = HERE.parent / "assets" / "plugin" / "journal-demandes"
ADAPTERS = {"cf7", "wpforms", "gravity", "elementor", "fluent", "formidable", "ninja"}
MODES = {"wp_consent_api", "cookiebot", "cookieyes", "axeptio", "cookie", "none"}
EMAIL = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
SLUG = re.compile(r"^[a-z0-9_\-]{1,20}$")


def php(value, indent=1):
    pad = "    " * indent
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)):
        return str(value)
    if value is None:
        return "null"
    if isinstance(value, str):
        return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"
    if isinstance(value, list):
        return "array(" + ", ".join(php(v, indent + 1) for v in value) + ")"
    if isinstance(value, dict):
        rows = [pad + php(str(k)) + " => " + php(v, indent + 1) for k, v in value.items()]
        return "array(\n" + ",\n".join(rows) + "\n" + "    " * (indent - 1) + ")"
    raise TypeError(type(value))


def validate(cfg):
    errors = []
    adapters = cfg.get("adapters", [])
    post_types = cfg.get("post_types", {})
    if not isinstance(adapters, list) or not set(adapters) <= ADAPTERS:
        errors.append("adapters doit être une liste parmi " + ", ".join(sorted(ADAPTERS)))
    if not isinstance(post_types, dict) or any(not SLUG.match(k) or not isinstance(v, str) or not v.strip() for k, v in post_types.items()):
        errors.append("post_types doit associer un type de contenu WordPress à un libellé")
    if not adapters and not post_types:
        errors.append("aucun formulaire raccordé : renseigner adapters ou post_types")
    consent = cfg.get("consent", {})
    mode = consent.get("mode")
    if mode not in MODES:
        errors.append("consent.mode doit valoir " + ", ".join(sorted(MODES)))
    if mode == "axeptio" and not consent.get("vendor"):
        errors.append("consent.vendor est requis pour Axeptio")
    if mode == "cookie":
        if not consent.get("cookie") or not (consent.get("allow") and consent.get("deny")):
            errors.append("consent.cookie, consent.allow et consent.deny sont requis en mode cookie")
        for key in ("allow", "deny"):
            try:
                re.compile(consent.get(key) or "")
            except re.error:
                errors.append("expression invalide pour consent." + key)
    email = cfg.get("notification_email", "")
    if email and not EMAIL.match(email):
        errors.append("notification_email invalide")
    if cfg.get("capability") and not re.match(r"^[a-z_]+$", cfg["capability"]):
        errors.append("capability invalide")
    return errors


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", required=True)
    ap.add_argument("--out", required=True)
    args = ap.parse_args()
    cfg = json.loads(Path(args.config).read_text(encoding="utf-8"))
    errors = validate(cfg)
    if errors:
        print("CONFIGURATION REFUSÉE")
        for e in errors:
            print(" * " + e)
        sys.exit(1)
    clean = {
        "site": cfg.get("site", ""),
        "adapters": cfg.get("adapters", []),
        "post_types": cfg.get("post_types", {}),
        "consent": {k: v for k, v in cfg["consent"].items() if k in ("mode", "category", "cookie", "allow", "deny", "vendor")},
        "notification_email": cfg.get("notification_email", ""),
        "capability": cfg.get("capability", "manage_options"),
        "track_logged_in": bool(cfg.get("track_logged_in", False)),
    }
    out = Path(args.out).expanduser().resolve()
    target = out / "journal-demandes"
    if target.exists():
        shutil.rmtree(target)
    shutil.copytree(TEMPLATE, target)
    header = "<?php\n// Configuration générée pour " + clean["site"].replace("\n", " ") + ". Modifier puis régénérer avec build_plugin.py.\nif (!defined('ABSPATH')) { exit; }\n"
    (target / "config.php").write_text(header + "return " + php(clean) + ";\n", encoding="utf-8")
    archive = out / "journal-demandes.zip"
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as z:
        for f in sorted(target.rglob("*")):
            if f.is_file():
                z.write(f, Path("journal-demandes") / f.relative_to(target))
    print("Archive : " + str(archive))
    print("Formulaires : " + ", ".join(clean["adapters"] + list(clean["post_types"].values())))
    print("Consentement : " + clean["consent"]["mode"])


if __name__ == "__main__":
    main()
