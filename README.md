      ██████╗ ███╗   ██╗███████╗███████╗ ██████╗ ██╗   ██╗██╗
      ██╔═══██╗████╗  ██║██╔════╝██╔════╝██╔═══██╗██║   ██║██║
      ██║   ██║██╔██╗ ██║█████╗  ███████╗██║   ██║██║   ██║██║
      ██║   ██║██║╚██╗██║██╔══╝  ╚════██║██║   ██║██║   ██║██║
      ╚██████╔╝██║ ╚████║███████╗███████║╚██████╔╝╚██████╔╝███████╗
      ╚═════╝ ╚═╝  ╚═══╝╚══════╝╚══════╝ ╚═════╝  ╚═════╝ ╚══════╝

      ██████╗  █████╗  ██████╗██╗  ██╗███████╗███╗   ██╗██████╗
      ██╔══██╗██╔══██╗██╔════╝██║ ██╔╝██╔════╝████╗  ██║██╔══██╗
      ██████╔╝███████║██║     █████╔╝ █████╗  ██╔██╗ ██║██║  ██║
      ██╔══██╗██╔══██║██║     ██╔═██╗ ██╔══╝  ██║╚██╗██║██║  ██║
      ██████╔╝██║  ██║╚██████╗██║  ██╗███████╗██║ ╚████║██████╔╝
---------------------------------------------------------------------------------------------------------------------

# Aplication configuration

The application configuration is stored in ./config/config.json, detailed below is its structure.

### so-deploy
```json
{
  "mysql": {
    "host": "database server url",
    "db": "database name",
    "port": database port,
    "username": "database username",
    "password": "database password"
  },
  "jwt": {
    "secret": "secret to encrypt tokens",
    "lifetime": token lifetime in seconds
  },
  "otp_exptime": otp expire time in seconds,
  "mailer": {
    "account" : "Google account",
    "password": "Google passwrd",
  },
  "recaptcha": {
    "secret": "reCaptcha token",
    "min_score": min reCaptcha score
  },
  "media_folder": {
    "url": "URL of media folder",
    "path": "server path for media"
  }
}
```
