# Respaldos automáticos de la base de datos

`database/backup.php` genera una copia completa de `sos_cosmeticos` en formato SQL. Usa `mysqldump` con una transacción consistente, por lo que las ventas y las demás operaciones pueden continuar mientras se crea el archivo. El respaldo incluye esquema, datos, rutinas, disparadores y eventos.

## Programar en Windows con XAMPP

1. Verificá que MySQL de XAMPP esté iniciado y que `php` esté disponible en el PATH.
2. Abrí PowerShell como el usuario que ejecuta la aplicación.
3. Ejecutá:

   ```powershell
   powershell -ExecutionPolicy Bypass -File "C:\ruta\al\proyecto\database\programar_backup.ps1"
   ```

El Programador de tareas de Windows ejecutará el respaldo todos los días a las 00:00, 06:00, 12:00 y 18:00. Si el equipo estaba apagado en uno de esos horarios, intentará ejecutar la tarea al volver a estar disponible. El equipo debe estar encendido y el servicio MySQL iniciado para que el respaldo se complete.

Los archivos se guardan fuera de la carpeta pública del sitio, en `SOSCosmeticosBackups` dentro del perfil del usuario que ejecuta la tarea. Se conservan 30 días y luego se eliminan únicamente los respaldos generados por este script. Para cambiar la ubicación, configurá `SOS_BACKUP_DIR` en el entorno del usuario antes de ejecutar la tarea.

## Ejecución y restauración manual

Para probar o ejecutar un respaldo manualmente:

```powershell
php "C:\ruta\al\proyecto\database\backup.php"
```

Si `mysqldump.exe` no está junto a PHP en la instalación de XAMPP, configurá `MYSQLDUMP_PATH` con su ruta completa. El script informa errores por consola y devuelve un código de salida distinto de cero cuando el respaldo falla. También evita respaldos simultáneos y no crea copias consistentes si encuentra tablas que no usan InnoDB.

Para restaurar, creá primero una base vacía y cargá el archivo `.sql` con MySQL:

```powershell
cmd /c 'mysql -u root -p sos_cosmeticos < "C:\ruta\al\respaldo.sql"'
```

Adaptá usuario, contraseña y ruta al entorno. La restauración reemplaza las tablas que contiene el archivo, por lo que debe hacerse solo con una copia elegida conscientemente. Probá periódicamente la restauración en una base de prueba: tener el archivo de respaldo no garantiza por sí solo que una restauración futura funcione.

Los archivos de respaldo contienen todos los datos de la aplicación, incluidas las credenciales cifradas y los registros de actividad. No los publiques ni los guardes en una carpeta accesible desde la web.
