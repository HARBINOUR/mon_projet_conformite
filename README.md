# Outil de Conformité d’Actes

Cette application PHP permet de téléverser un fichier CSV contenant des actes (NGAP/CCAM), de les comparer à une base de données (MySQL, Oracle, etc.), et d'exporter la liste des actes manquants.

## Prérequis

- PHP 8.1 ou supérieur
- Composer (https://getcomposer.org/)
- Un serveur web (Apache, Nginx) ou le serveur de développement intégré de PHP
- Accès à une base de données (MySQL, Oracle, etc.)

## Installation

1.  **Clonez le dépôt :**

    ```bash
    git clone <repository-url>
    cd mon_projet_conformite
    ```

2.  **Installez les dépendances avec Composer :**
    Cette commande lira `composer.json` et créera un dossier `vendor/` contenant l'autoloader.

    ```bash
    composer install
    ```

3.  **Créez le fichier de configuration :**
    Copiez `config/config.sample.php` vers `config/config.php` et ajustez les paramètres de connexion à votre base de données Oracle.
    ```bash
    cp config/config.sample.php config/config.php
    ```

## Lancement

La manière la plus simple de lancer l'application est d'utiliser le serveur de développement intégré de PHP, en pointant vers le dossier `public/`.

```bash
php -S localhost:8080 -t public
```

Ouvrez ensuite votre navigateur à l'adresse `http://localhost:8080`.
