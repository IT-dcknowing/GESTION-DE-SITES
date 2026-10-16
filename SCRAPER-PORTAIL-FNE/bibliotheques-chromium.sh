#!/usr/bin/env bash
#
# Les bibliothèques qui manquent à Chromium, posées dans votre dossier — sans droits administrateur.
#
# Pourquoi (16/10) : sur l'hébergement mutualisé, Chromium s'arrête au lancement — « error while
# loading shared libraries: libatk-bridge-2.0.so.0 » — et `npx playwright install-deps` exige
# d'être administrateur. Ce script télécharge les mêmes bibliothèques depuis les dépôts du
# serveur lui-même (donc compatibles), les déballe dans ~/fne-libs, puis vérifie ce qui manque
# encore. Il n'installe rien dans le système et ne touche à rien d'autre.
#
# Usage, depuis le dossier SCRAPER-PORTAIL-FNE :   bash bibliotheques-chromium.sh

set -u
shopt -s nullglob
DEST="$HOME/fne-libs"
RPMS="$DEST/rpm"
CHEMIN_LIBS="$DEST/usr/lib64:$DEST/lib64:$DEST/usr/lib:$DEST/lib"
mkdir -p "$RPMS"

echo "== Système"
cat /etc/redhat-release 2>/dev/null || grep -E '^(NAME|VERSION)=' /etc/os-release 2>/dev/null || echo "   (non lisible ici)"

echo "== Outils"
for outil in ldd dnf yumdownloader rpm2cpio cpio; do
  printf '   %-14s %s\n' "$outil" "$(command -v "$outil" || echo 'ABSENT')"
done

# Une simple liste par motif, sans `< <(…)` : l'hébergement (CageFS) n'a pas /dev/fd, et la
# première version du script échouait là sans le dire — puis concluait « C'EST BON » sur une
# liste vide (16/10). Désormais, pas de navigateur trouvé = arrêt.
echo "== Navigateurs installés par Playwright"
BINAIRES=( "$HOME"/.cache/ms-playwright/chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell
           "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux64/chrome )
if [ ${#BINAIRES[@]} -eq 0 ]; then
  echo "   Aucun trouvé. Lancez d'abord : npx playwright install chromium"; exit 1
fi
printf '   %s\n' "${BINAIRES[@]}"

if ! command -v ldd >/dev/null 2>&1; then
  echo "   ldd est absent : impossible de vérifier. Envoyez ce texte."; exit 3
fi

manquantes() {
  for b in "${BINAIRES[@]}"; do
    LD_LIBRARY_PATH="$CHEMIN_LIBS" ldd "$b" 2>/dev/null | awk '/not found/ {print $1}'
  done | sort -u
}

echo "== Bibliothèques manquantes avant"
AVANT=$(manquantes)
if [ -z "$AVANT" ]; then
  echo "   aucune — Chromium a tout ce qu'il lui faut."
else
  printf '   %s\n' $AVANT

  # Les paquets qui les fournissent sur les systèmes de la famille Red Hat (CloudLinux, AlmaLinux).
  PAQUETS="atk at-spi2-atk at-spi2-core cups-libs libxkbcommon libXcomposite libXdamage libXrandr libXfixes \
libXext libX11 libxcb libXi libXtst libXrender libXau mesa-libgbm libdrm pango cairo harfbuzz fribidi \
fontconfig freetype pixman alsa-lib nss nss-util nss-softokn nss-softokn-freebl nspr expat dbus-libs \
libwayland-server libwayland-client libxshmfence avahi-libs gnutls libepoxy"

  echo "== Téléchargement des paquets (sans rien installer)"
  OK=0
  if command -v dnf >/dev/null 2>&1; then
    dnf download --destdir "$RPMS" --setopt=cachedir="$HOME/.cache/dnf" $PAQUETS >/dev/null 2>"$RPMS/dnf.log" && OK=1
  fi
  if [ $OK -eq 0 ] && command -v yumdownloader >/dev/null 2>&1; then
    yumdownloader --destdir "$RPMS" $PAQUETS >/dev/null 2>"$RPMS/yum.log" && OK=1
  fi
  if [ $OK -eq 0 ] && ! ls "$RPMS"/*.rpm >/dev/null 2>&1; then
    echo "   Impossible de télécharger les paquets ici (ni dnf download, ni yumdownloader)."
    echo "   L'hébergement ne permet pas de poser ces bibliothèques soi-même : voir CONSIGNES.md,"
    echo "   « Si le script ne suffit pas » (demande à l'hébergeur, ou relevé depuis un PC)."
    cat "$RPMS"/*.log 2>/dev/null | tail -5
    exit 2
  fi

  echo "== Déballage dans $DEST"
  for f in "$RPMS"/*.rpm; do
    ( cd "$DEST" && rpm2cpio "$f" | cpio -idm --quiet 2>/dev/null )
  done
fi

echo "== Bibliothèques manquantes après"
APRES=$(manquantes)
if [ -z "$APRES" ]; then
  echo "   aucune."
  echo
  echo "C'EST BON. Ajoutez cette ligne dans SCRAPER-PORTAIL-FNE/.env, puis relancez le relevé :"
  echo
  echo "FNE_LIBS=$CHEMIN_LIBS"
else
  printf '   %s\n' $APRES
  echo
  echo "Il en manque encore : envoyez tout ce texte."
fi
