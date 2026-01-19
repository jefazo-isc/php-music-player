# php-music-player

**php-music-player** es un reproductor de música web, minimalista y autohospedado, diseñado para funcionar con **cero dependencias externas**. No requiere bases de datos (SQL), frameworks ni librerías complejas. Simplemente sube los archivos y reproduce tu colección local.

El proyecto destaca por su capacidad de analizar metadatos técnicos y extraer carátulas incrustadas directamente de los bits de los archivos de audio (FLAC/MP3) sin usar librerías como `getID3`.

![Screenshot del Reproductor](https://via.placeholder.com/800x450?text=Agrega+una+captura+de+pantalla+aqui)

## 🚀 Características

### Backend (PHP)
* **Cero Base de Datos:** Escanea directorios recursivamente y crea una caché ligera en JSON.
* **Soporte de Formatos:** MP3, FLAC, OGG, WAV, M4A, AAC, OPUS.
* **Motor de Análisis "Forense":**
    * Lectura binaria manual de cabeceras ID3v2 y bloques FLAC.
    * **Extracción de Carátulas "Nuclear":** Si la extracción estándar falla, escanea el archivo byte a byte buscando firmas hexadecimales de imágenes (JPEG/PNG) (ideal para archivos con metadatos corruptos).
    * Detección real de Bitrate, Sample Rate y Profundidad de Bits (16/24-bit).
* **Streaming Eficiente:** Soporte para `Range Requests` (permite adelantar/atrasar la canción sin descargarla toda).

### Frontend (Vanilla JS + CSS)
* **Single Page Application (SPA):** Navegación fluida sin recargas.
* **Visualizador de Audio:** Renderizado en tiempo real usando Canvas API y Web Audio API.
* **Ecualizador de 10 Bandas:** Totalmente funcional y persistente.
* **Gestión de Listas:**
    * Creación de Playlists locales.
    * Sistema de Favoritos.
    * Persistencia usando `localStorage`.
* **Interfaz Reactiva:** Diseño *Glassmorphism* oscuro, adaptable a móviles y escritorio.
* **Teclas de Acceso Rápido:** Espacio (Play/Pause), Flechas (Seek).

## 📋 Requisitos

* Servidor Web (Apache, Nginx, o PHP Built-in Server).
* PHP 7.4 o superior.
* Permisos de lectura en tu directorio de música.
* Permisos de escritura en el directorio temporal del sistema (para el archivo de caché).

## 🔧 Instalación

1.  **Clonar el repositorio:**
    ```bash
    git clone [https://github.com/tu-usuario/php-music-player.git](https://github.com/tu-usuario/php-music-player.git)
    cd php-music-player
    ```

2.  **Desplegar:**
    Copia los archivos (`index.php`, `css.css`, `js.js`) a tu directorio web público (ej. `/var/www/html/player`).

3.  **Configurar:**
    * Abre el navegador y ve a tu URL.
    * Haz clic en el icono de **Engranaje** ⚙️.
    * Ingresa la **ruta absoluta** de tu carpeta de música en el servidor (ej. `/home/usuario/Music` o `C:\Users\Music`).
    * Haz clic en **Guardar**.

## 🛠️ Configuración Avanzada

Si tienes archivos muy grandes o una colección masiva, puedes ajustar los límites en `index.php`:

```php
// index.php - Líneas 2-4
set_time_limit(300); // Aumentar si el escaneo inicial falla por timeout
ini_set('memory_limit', '512M'); // Aumentar si procesas FLACs muy pesados para extracción de arte
