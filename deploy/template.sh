#!/bin/sh

clear
VERSION="{VERSION}"
cd "$(dirname "$0")"
while :
do
	cat << END


     ██████╗ ███╗   ██╗███████╗███████╗ ██████╗ ██╗   ██╗██╗
    ██╔═══██╗████╗  ██║██╔════╝██╔════╝██╔═══██╗██║   ██║██║
    ██║   ██║██╔██╗ ██║█████╗  ███████╗██║   ██║██║   ██║██║
    ██║   ██║██║╚██╗██║██╔══╝  ╚════██║██║   ██║██║   ██║██║
    ╚██████╔╝██║ ╚████║███████╗███████║╚██████╔╝╚██████╔╝███████╗
     ╚═════╝ ╚═╝  ╚═══╝╚══════╝╚══════╝ ╚═════╝  ╚═════╝ ╚══════╝
  -----------------------  BACKEND v$VERSION  ----------------------
END
  echo ""
  echo "1: Instalar"
  echo "2: Salir"
  echo -n "Seleccione una opción [1 - 2]"
  read opcion
  case $opcion in
    1)
      echo "Instalando backend"
      break;;
    2)
      exit 1
      break;;
    *)
      echo "Opción no válida";;
  esac
done

deploy={PAYLOAD}
echo $deploy | openssl base64 -d -A > ./deploy.tar.gz

# Detectar instalacion previa
if [ -e "./public/index.php" ]; then
  # Hacer limpieza y respaldar las imagenes de los productos
  echo "Instalación previa detectada, haciendo limpieza"
  rm -rf ./public 2>/dev/null
  rm -rf ./src 2>/dev/null
  rm -rf ./vendor 2>/dev/null
  rm  ./composer.json 2>/dev/null
  rm ./composer.lock 2>/dev/null
	# Desempaqueto el instalador
	tar -zxf deploy.tar.gz
else
  while :
  do
    echo "No se detectó una instalación previa"
    echo "Veerifique que el directorio sea correcto"
    echo "Directorio de instalación: `pwd`"
    echo -n "Continuar? [s/n]"
    read opcion
    case $opcion in
      s)
        tar -zxf deploy.tar.gz
        break;;
      n)
        echo "Instalación cancelada"
        rm deploy.tar.gz
        exit 1;;
      *)
        echo "Opción no válida";;
    esac
  done

fi

# Borro el instalador y el payload
echo "Limpiando temporales"
rm ./deploy.tar.gz
rm ./install-*.sh 2>/dev/null
echo $VERSION > .version

# Ejecutar composer
echo "Instalando dependencias"
composer install --no-dev
composer dump-autoload

echo "Instalación finalizada"
exit 1

