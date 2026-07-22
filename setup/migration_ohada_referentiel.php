<?php
/**
 * migration_ohada_referentiel.php
 * Crée et peuple la table ohada_plan_comptable
 * selon le Plan Comptable SYSCOHADA Révisé 2017 (OHADA 2017)
 *
 * Structure de la table :
 *   niveau       : 1=Classe, 2=Compte principal (2 chiffres),
 *                  3=Compte divisionnaire (3 chiffres), 4=Sous-compte (4 chiffres)
 *   classe       : 1 chiffre (1–8)
 *   libelle_classe
 *   compte_2     : 2 chiffres
 *   libelle_2
 *   compte_3     : 3 chiffres
 *   libelle_3
 *   compte_4     : 4 chiffres
 *   libelle_4
 *
 * Usage : ouvrir http://localhost/comptabilite_ohada/setup/migration_ohada_referentiel.php
 */

require_once '../config/config.php';
$db = Database::getInstance()->getConnection();

// ─── Création de la table ─────────────────────────────────────────────────────
$db->exec("DROP TABLE IF EXISTS ohada_plan_comptable");
$db->exec("
CREATE TABLE ohada_plan_comptable (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    niveau         TINYINT     NOT NULL COMMENT '1=classe 2=principal 3=divisionnaire 4=sous-compte',
    classe         CHAR(1)     NOT NULL,
    libelle_classe VARCHAR(200) NOT NULL,
    compte_2       CHAR(2)     DEFAULT NULL,
    libelle_2      VARCHAR(200) DEFAULT NULL,
    compte_3       CHAR(3)     DEFAULT NULL,
    libelle_3      VARCHAR(200) DEFAULT NULL,
    compte_4       CHAR(4)     DEFAULT NULL,
    libelle_4      VARCHAR(200) DEFAULT NULL,
    bd             VARCHAR(10)  DEFAULT NULL COMMENT 'Rubrique bilan débit',
    bc             VARCHAR(10)  DEFAULT NULL COMMENT 'Rubrique bilan crédit',
    rd             VARCHAR(10)  DEFAULT NULL COMMENT 'Rubrique résultat débit',
    rc             VARCHAR(10)  DEFAULT NULL COMMENT 'Rubrique résultat crédit',
    INDEX idx_niveau  (niveau),
    INDEX idx_classe  (classe),
    INDEX idx_c2      (compte_2),
    INDEX idx_c3      (compte_3),
    INDEX idx_c4      (compte_4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ─── Données ──────────────────────────────────────────────────────────────────
// Format : [niveau, classe, libelle_classe, compte_2, libelle_2, compte_3, libelle_3, compte_4, libelle_4]
// null = non applicable à ce niveau
$rows = [

// ══════════════════════════════════════════════════════════════════════════════
// CLASSE 1 — Comptes de ressources durables
// ══════════════════════════════════════════════════════════════════════════════
[1,'1','Comptes de ressources durables',null,null,null,null,null,null],
// 10 Capital
[2,'1','Comptes de ressources durables','10','Capital',null,null,null,null],
[3,'1','Comptes de ressources durables','10','Capital','101','Capital social',null,null],
[4,'1','Comptes de ressources durables','10','Capital','101','Capital social','1011','Capital souscrit, non appelé'],
[4,'1','Comptes de ressources durables','10','Capital','101','Capital social','1012','Capital souscrit, appelé, non versé'],
[4,'1','Comptes de ressources durables','10','Capital','101','Capital social','1013','Capital souscrit, appelé, versé, non amorti'],
[4,'1','Comptes de ressources durables','10','Capital','101','Capital social','1014','Capital souscrit, appelé, versé, amorti'],
[4,'1','Comptes de ressources durables','10','Capital','101','Capital social','1018','Capital souscrit soumis à des conditions particulières'],
[3,'1','Comptes de ressources durables','10','Capital','102','Capital par dotation',null,null],
[4,'1','Comptes de ressources durables','10','Capital','102','Capital par dotation','1021','Dotation initiale'],
[4,'1','Comptes de ressources durables','10','Capital','102','Capital par dotation','1022','Dotations complémentaires'],
[4,'1','Comptes de ressources durables','10','Capital','102','Capital par dotation','1028','Autres dotations'],
[3,'1','Comptes de ressources durables','10','Capital','103','Capital personnel',null,null],
[3,'1','Comptes de ressources durables','10','Capital','104',"Compte de l'exploitant",null,null],
[4,'1','Comptes de ressources durables','10','Capital','104',"Compte de l'exploitant",'1041','Apports temporaires'],
[4,'1','Comptes de ressources durables','10','Capital','104',"Compte de l'exploitant",'1042','Opérations courantes'],
[4,'1','Comptes de ressources durables','10','Capital','104',"Compte de l'exploitant",'1043','Rémunérations, impôts et autres charges personnelles'],
[4,'1','Comptes de ressources durables','10','Capital','104',"Compte de l'exploitant",'1047',"Prélèvements d'autoconsommation"],
[4,'1','Comptes de ressources durables','10','Capital','104',"Compte de l'exploitant",'1048','Autres prélèvements'],
[3,'1','Comptes de ressources durables','10','Capital','105','Primes liées au capital social',null,null],
[4,'1','Comptes de ressources durables','10','Capital','105','Primes liées au capital social','1051',"Primes d'émission"],
[4,'1','Comptes de ressources durables','10','Capital','105','Primes liées au capital social','1052',"Primes d'apport"],
[4,'1','Comptes de ressources durables','10','Capital','105','Primes liées au capital social','1053','Primes de fusion'],
[4,'1','Comptes de ressources durables','10','Capital','105','Primes liées au capital social','1054','Primes de conversion'],
[4,'1','Comptes de ressources durables','10','Capital','105','Primes liées au capital social','1058','Autres primes'],
[3,'1','Comptes de ressources durables','10','Capital','106',"Écarts de réévaluation",null,null],
[4,'1','Comptes de ressources durables','10','Capital','106',"Écarts de réévaluation",'1061',"Écarts de réévaluation légale"],
[4,'1','Comptes de ressources durables','10','Capital','106',"Écarts de réévaluation",'1062',"Écarts de réévaluation libre"],
[3,'1','Comptes de ressources durables','10','Capital','109','Apporteurs, capital souscrit, non appelé',null,null],
// 11 Réserves
[2,'1','Comptes de ressources durables','11','Réserves',null,null,null,null],
[3,'1','Comptes de ressources durables','11','Réserves','111','Réserve légale',null,null],
[3,'1','Comptes de ressources durables','11','Réserves','112','Réserves statutaires ou contractuelles',null,null],
[3,'1','Comptes de ressources durables','11','Réserves','113','Réserves réglementées',null,null],
[4,'1','Comptes de ressources durables','11','Réserves','113','Réserves réglementées','1131','Réserves de plus-values nettes à long terme'],
[4,'1','Comptes de ressources durables','11','Réserves','113','Réserves réglementées','1132',"Réserves d'attribution gratuite d'actions au personnel salarié et aux dirigeants"],
[4,'1','Comptes de ressources durables','11','Réserves','113','Réserves réglementées','1133',"Réserves consécutives à l'octroi de subventions d'investissement"],
[4,'1','Comptes de ressources durables','11','Réserves','113','Réserves réglementées','1134','Réserves des valeurs mobilières donnant accès au capital'],
[4,'1','Comptes de ressources durables','11','Réserves','113','Réserves réglementées','1138','Autres réserves réglementées'],
[3,'1','Comptes de ressources durables','11','Réserves','118','Autres réserves',null,null],
[4,'1','Comptes de ressources durables','11','Réserves','118','Autres réserves','1181','Réserves facultatives'],
[4,'1','Comptes de ressources durables','11','Réserves','118','Autres réserves','1188','Réserves diverses'],
// 12 Report à nouveau
[2,'1','Comptes de ressources durables','12','Report à nouveau',null,null,null,null],
[3,'1','Comptes de ressources durables','12','Report à nouveau','121','Report à nouveau créditeur',null,null],
[3,'1','Comptes de ressources durables','12','Report à nouveau','129','Report à nouveau débiteur',null,null],
[4,'1','Comptes de ressources durables','12','Report à nouveau','129','Report à nouveau débiteur','1291','Perte nette à reporter'],
[4,'1','Comptes de ressources durables','12','Report à nouveau','129','Report à nouveau débiteur','1292',"Perte - Amortissements réputés différés"],
// 13 Résultat
[2,'1','Comptes de ressources durables','13',"Résultat net de l'exercice",null,null,null,null],
[3,'1','Comptes de ressources durables','13',"Résultat net de l'exercice",'130',"Résultat en instance d'affectation",null,null],
[3,'1','Comptes de ressources durables','13',"Résultat net de l'exercice",'131','Résultat net : bénéfice',null,null],
[3,'1','Comptes de ressources durables','13',"Résultat net de l'exercice",'139','Résultat net : perte',null,null],
// 14 Subventions d'investissement
[2,'1','Comptes de ressources durables','14',"Subventions d'investissement",null,null,null,null],
[3,'1','Comptes de ressources durables','14',"Subventions d'investissement",'141',"Subventions d'équipement",null,null],
[3,'1','Comptes de ressources durables','14',"Subventions d'investissement",'148',"Autres subventions d'investissement",null,null],
// 15 Provisions réglementées
[2,'1','Comptes de ressources durables','15','Provisions réglementées et fonds assimilés',null,null,null,null],
[3,'1','Comptes de ressources durables','15','Provisions réglementées et fonds assimilés','151','Amortissements dérogatoires',null,null],
[3,'1','Comptes de ressources durables','15','Provisions réglementées et fonds assimilés','152','Plus-values de cession à réinvestir',null,null],
[3,'1','Comptes de ressources durables','15','Provisions réglementées et fonds assimilés','155','Provisions réglementées relatives aux immobilisations',null,null],
[3,'1','Comptes de ressources durables','15','Provisions réglementées et fonds assimilés','157','Provisions pour investissement',null,null],
// 16 Emprunts
[2,'1','Comptes de ressources durables','16','Emprunts et dettes assimilées',null,null,null,null],
[3,'1','Comptes de ressources durables','16','Emprunts et dettes assimilées','161','Emprunts obligataires',null,null],
[3,'1','Comptes de ressources durables','16','Emprunts et dettes assimilées','162','Emprunts et dettes auprès des établissements de crédit',null,null],
[3,'1','Comptes de ressources durables','16','Emprunts et dettes assimilées','163',"Avances reçues de l'État",null,null],
[3,'1','Comptes de ressources durables','16','Emprunts et dettes assimilées','164','Avances reçues et comptes courants bloqués',null,null],
[3,'1','Comptes de ressources durables','16','Emprunts et dettes assimilées','165','Dépôts et cautionnements reçus',null,null],
[3,'1','Comptes de ressources durables','16','Emprunts et dettes assimilées','166','Intérêts courus',null,null],
[3,'1','Comptes de ressources durables','16','Emprunts et dettes assimilées','167','Avances assorties de conditions particulières',null,null],
[3,'1','Comptes de ressources durables','16','Emprunts et dettes assimilées','168','Autres emprunts et dettes',null,null],
// 17 Dettes de location acquisition
[2,'1','Comptes de ressources durables','17','Dettes de location acquisition',null,null,null,null],
[3,'1','Comptes de ressources durables','17','Dettes de location acquisition','172','Dettes de location acquisition / crédit-bail immobilier',null,null],
[3,'1','Comptes de ressources durables','17','Dettes de location acquisition','173','Dettes de location acquisition / crédit-bail mobilier',null,null],
[3,'1','Comptes de ressources durables','17','Dettes de location acquisition','174','Dettes de location acquisition / location de vente',null,null],
[3,'1','Comptes de ressources durables','17','Dettes de location acquisition','178','Autres dettes de location acquisition',null,null],
// 18 Dettes liées à des participations
[2,'1','Comptes de ressources durables','18','Dettes liées à des participations et comptes de liaison',null,null,null,null],
[3,'1','Comptes de ressources durables','18','Dettes liées à des participations et comptes de liaison','181','Dettes liées à des participations',null,null],
[3,'1','Comptes de ressources durables','18','Dettes liées à des participations et comptes de liaison','182','Dettes liées à des sociétés en participation',null,null],
// 19 Provisions pour risques
[2,'1','Comptes de ressources durables','19','Provisions pour risques et charges',null,null,null,null],
[3,'1','Comptes de ressources durables','19','Provisions pour risques et charges','191','Provisions pour litiges',null,null],
[3,'1','Comptes de ressources durables','19','Provisions pour risques et charges','192','Provisions pour garanties données aux clients',null,null],
[3,'1','Comptes de ressources durables','19','Provisions pour risques et charges','194','Provisions pour pertes de change',null,null],
[3,'1','Comptes de ressources durables','19','Provisions pour risques et charges','195','Provisions pour impôts',null,null],
[3,'1','Comptes de ressources durables','19','Provisions pour risques et charges','196','Provisions pour pensions et obligations similaires',null,null],
[3,'1','Comptes de ressources durables','19','Provisions pour risques et charges','198','Autres provisions pour risques et charges',null,null],

// ══════════════════════════════════════════════════════════════════════════════
// CLASSE 2 — Comptes d'actif immobilisé
// ══════════════════════════════════════════════════════════════════════════════
[1,'2',"Comptes d'actif immobilisé",null,null,null,null,null,null],
// 21 Immobilisations incorporelles
[2,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles',null,null,null,null],
[3,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','211','Frais de développement',null,null],
[3,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','212','Brevets, licences, concessions et droits similaires',null,null],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','212','Brevets, licences, concessions et droits similaires','2121','Brevets'],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','212','Brevets, licences, concessions et droits similaires','2122','Licences'],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','212','Brevets, licences, concessions et droits similaires','2123','Concessions de service public'],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','212','Brevets, licences, concessions et droits similaires','2128','Autres concessions et droits similaires'],
[3,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','213','Logiciels et sites internet',null,null],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','213','Logiciels et sites internet','2131','Logiciels'],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','213','Logiciels et sites internet','2132','Sites internet'],
[3,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','214','Marques',null,null],
[3,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','215','Fonds commercial',null,null],
[3,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','216','Droit au bail',null,null],
[3,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','217','Investissements de création',null,null],
[3,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','218','Autres droits et valeurs incorporels',null,null],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','218','Autres droits et valeurs incorporels','2181','Frais de prospection et d\'évaluation de ressources minérales'],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','218','Autres droits et valeurs incorporels','2182',"Coûts d'obtention du contrat"],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','218','Autres droits et valeurs incorporels','2183','Fichiers clients, notices, titres de journaux et magazines'],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','218','Autres droits et valeurs incorporels','2184','Coûts des franchises'],
[4,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','218','Autres droits et valeurs incorporels','2188','Divers droits et valeurs incorporelles'],
[3,'2',"Comptes d'actif immobilisé",'21','Immobilisations incorporelles','219','Immobilisations incorporelles en cours',null,null],
// 22 Terrains
[2,'2',"Comptes d'actif immobilisé",'22','Terrains',null,null,null,null],
[3,'2',"Comptes d'actif immobilisé",'22','Terrains','221','Terrains agricoles et forestiers',null,null],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','221','Terrains agricoles et forestiers','2211',"Terrains d'exploitation agricole"],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','221','Terrains agricoles et forestiers','2212',"Terrains d'exploitation forestière"],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','221','Terrains agricoles et forestiers','2218','Autres terrains'],
[3,'2',"Comptes d'actif immobilisé",'22','Terrains','222','Terrains nus',null,null],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','222','Terrains nus','2221','Terrains à bâtir'],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','222','Terrains nus','2228','Autres terrains nus'],
[3,'2',"Comptes d'actif immobilisé",'22','Terrains','223','Terrains bâtis',null,null],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','223','Terrains bâtis','2231','Pour bâtiments industriels et agricoles'],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','223','Terrains bâtis','2232','Pour bâtiments administratifs et commerciaux'],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','223','Terrains bâtis','2234','Pour bâtiments affectés aux autres opérations professionnelles'],
[3,'2',"Comptes d'actif immobilisé",'22','Terrains','224','Travaux de mise en valeur des terrains',null,null],
[3,'2',"Comptes d'actif immobilisé",'22','Terrains','225',"Terrains de carrières - tréfonds",null,null],
[3,'2',"Comptes d'actif immobilisé",'22','Terrains','226','Terrains aménagés',null,null],
[3,'2',"Comptes d'actif immobilisé",'22','Terrains','227','Terrains mis en concession',null,null],
[3,'2',"Comptes d'actif immobilisé",'22','Terrains','228','Autres terrains',null,null],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','228','Autres terrains','2281','Terrains - immeubles de placement'],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','228','Autres terrains','2285','Terrains des logements affectés au personnel'],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','228','Autres terrains','2286','Terrains de location - acquisition'],
[4,'2',"Comptes d'actif immobilisé",'22','Terrains','228','Autres terrains','2288','Divers terrains'],
[3,'2',"Comptes d'actif immobilisé",'22','Terrains','229','Aménagements de terrains en cours',null,null],
// 23 Bâtiments
[2,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements',null,null,null,null],
[3,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','231','Bâtiments industriels, agricoles, administratifs et commerciaux sur sol propre',null,null],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','231','Bâtiments industriels, agricoles, administratifs et commerciaux sur sol propre','2311','Bâtiments industriels'],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','231','Bâtiments industriels, agricoles, administratifs et commerciaux sur sol propre','2312','Bâtiments agricoles'],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','231','Bâtiments industriels, agricoles, administratifs et commerciaux sur sol propre','2313','Bâtiments administratifs et commerciaux'],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','231','Bâtiments industriels, agricoles, administratifs et commerciaux sur sol propre','2314','Bâtiments affectés au logement du personnel'],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','231','Bâtiments industriels, agricoles, administratifs et commerciaux sur sol propre','2315','Bâtiments - immeubles de placement'],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','231','Bâtiments industriels, agricoles, administratifs et commerciaux sur sol propre','2316','Bâtiments de location - acquisition'],
[3,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','232','Bâtiments industriels, agricoles, administratifs et commerciaux sur sol d\'autrui',null,null],
[3,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','233',"Ouvrages d'infrastructure",null,null],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','233',"Ouvrages d'infrastructure",'2331','Voies de terre'],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','233',"Ouvrages d'infrastructure",'2332','Voies de fer'],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','233',"Ouvrages d'infrastructure",'2334','Barrages, Digues'],
[3,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','234','Aménagements, agencements et installations techniques',null,null],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','234','Aménagements, agencements et installations techniques','2341','Installations complexes spécialisées sur sol propre'],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','234','Aménagements, agencements et installations techniques','2342','Installations complexes spécialisées sur sol d\'autrui'],
[4,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','234','Aménagements, agencements et installations techniques','2345','Aménagements et agencements des bâtiments'],
[3,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','235','Aménagements de bureaux',null,null],
[3,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','237','Bâtiments industriels, agricoles et commerciaux mis en concession',null,null],
[3,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','238','Autres installations et agencements',null,null],
[3,'2',"Comptes d'actif immobilisé",'23','Bâtiments, installations techniques et agencements','239','Bâtiments aménagements, agencements et installations en cours',null,null],
// 24 Matériel, mobilier et actifs biologiques
[2,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques',null,null,null,null],
[3,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','241','Matériel et outillage industriel et commercial',null,null],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','241','Matériel et outillage industriel et commercial','2411','Matériel industriel'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','241','Matériel et outillage industriel et commercial','2412','Outillage industriel'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','241','Matériel et outillage industriel et commercial','2413','Matériel commercial'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','241','Matériel et outillage industriel et commercial','2414','Outillage commercial'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','241','Matériel et outillage industriel et commercial','2416','Matériel & outillage industriel et commercial de location-acquisition'],
[3,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','242','Matériel et outillage agricole',null,null],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','242','Matériel et outillage agricole','2421','Matériel agricole'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','242','Matériel et outillage agricole','2422','Outillage agricole'],
[3,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','243',"Matériel d'emballage récupérable et identifiable",null,null],
[3,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','244','Matériel et mobilier',null,null],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','244','Matériel et mobilier','2441','Matériel de bureau'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','244','Matériel et mobilier','2442','Matériel informatique'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','244','Matériel et mobilier','2443','Matériel bureautique'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','244','Matériel et mobilier','2444','Mobilier de bureau'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','244','Matériel et mobilier','2445','Matériel et mobilier - immeubles de placement'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','244','Matériel et mobilier','2446','Matériel et mobilier de location - acquisition'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','244','Matériel et mobilier','2447','Matériel et mobilier des logements du personnel'],
[3,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','245','Matériel de transport',null,null],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','245','Matériel de transport','2451','Matériel automobile'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','245','Matériel de transport','2452','Matériel ferroviaire'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','245','Matériel de transport','2453','Matériel fluvial, lagunaire'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','245','Matériel de transport','2454','Matériel naval'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','245','Matériel de transport','2455','Matériel aérien'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','245','Matériel de transport','2456','Matériel de transport de location-acquisition'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','245','Matériel de transport','2458','Autres matériels de transport'],
[3,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','246','Actifs biologiques',null,null],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','246','Actifs biologiques','2461','Cheptel, animaux de trait'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','246','Actifs biologiques','2462','Cheptel, animaux reproducteurs'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','246','Actifs biologiques','2463','Animaux de garde'],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','246','Actifs biologiques','2465','Plantations agricoles'],
[3,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','247','Agencements, aménagements du matériel et actifs biologiques',null,null],
[3,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','248','Autres matériels et mobiliers',null,null],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','248','Autres matériels et mobiliers','2481',"Collections et œuvres d'art"],
[4,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','248','Autres matériels et mobiliers','2488','Divers matériels mobiliers'],
[3,'2',"Comptes d'actif immobilisé",'24','Matériel, mobilier et actifs biologiques','249','Matériels et actifs biologiques en cours',null,null],
// 25 Avances sur immobilisations
[2,'2',"Comptes d'actif immobilisé",'25','Avances et acomptes versés sur immobilisations',null,null,null,null],
[3,'2',"Comptes d'actif immobilisé",'25','Avances et acomptes versés sur immobilisations','251','Avances et acomptes versés sur immobilisations incorporelles',null,null],
[3,'2',"Comptes d'actif immobilisé",'25','Avances et acomptes versés sur immobilisations','252','Avances et acomptes versés sur immobilisations corporelles',null,null],
// 26 Titres de participation
[2,'2',"Comptes d'actif immobilisé",'26','Titres de participation',null,null,null,null],
[3,'2',"Comptes d'actif immobilisé",'26','Titres de participation','261','Titres de participation dans des sociétés sous contrôle exclusif',null,null],
[3,'2',"Comptes d'actif immobilisé",'26','Titres de participation','262','Titres de participation dans des sociétés sous contrôle conjoint',null,null],
[3,'2',"Comptes d'actif immobilisé",'26','Titres de participation','263','Titres de participation dans des sociétés conférant une influence notable',null,null],
[3,'2',"Comptes d'actif immobilisé",'26','Titres de participation','265','Participations dans des organismes professionnels',null,null],
[3,'2',"Comptes d'actif immobilisé",'26','Titres de participation','266',"Parts dans des groupements d'intérêt économique (GIE)",null,null],
[3,'2',"Comptes d'actif immobilisé",'26','Titres de participation','268','Autres titres de participation',null,null],
// 27 Autres immo financières
[2,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières',null,null,null,null],
[3,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','271','Prêts et créances',null,null],
[4,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','271','Prêts et créances','2711','Prêts participatifs'],
[4,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','271','Prêts et créances','2712','Prêts aux associés'],
[4,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','271','Prêts et créances','2718','Autres prêts et créances'],
[3,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','272','Prêts au personnel',null,null],
[3,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','274','Titres immobilisés',null,null],
[4,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','274','Titres immobilisés','2741','Titres immobilisés de l\'activité de portefeuille (TIAP)'],
[4,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','274','Titres immobilisés','2745','Obligations'],
[4,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','274','Titres immobilisés','2746','Actions ou parts propres'],
[3,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','275','Dépôts et cautionnements versés',null,null],
[4,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','275','Dépôts et cautionnements versés','2751','Dépôts pour loyers d\'avance'],
[4,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','275','Dépôts et cautionnements versés','2752',"Dépôts pour l'électricité"],
[4,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','275','Dépôts et cautionnements versés','2756','Cautionnements sur marchés publics'],
[4,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','275','Dépôts et cautionnements versés','2758','Autres dépôts et cautionnements'],
[3,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','276','Intérêts courus',null,null],
[3,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','277','Créances rattachées à des participations et avances à des GIE',null,null],
[3,'2',"Comptes d'actif immobilisé",'27','Autres immobilisations financières','278','Immobilisations financières diverses',null,null],
// 28 Amortissements
[2,'2',"Comptes d'actif immobilisé",'28','Amortissements',null,null,null,null],
[3,'2',"Comptes d'actif immobilisé",'28','Amortissements','281','Amortissements des immobilisations incorporelles',null,null],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','281','Amortissements des immobilisations incorporelles','2811','Amortissements des frais de développement'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','281','Amortissements des immobilisations incorporelles','2812','Amortissements des brevets, licences, concessions et droits similaires'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','281','Amortissements des immobilisations incorporelles','2813','Amortissements des logiciels et sites internet'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','281','Amortissements des immobilisations incorporelles','2814','Amortissements des marques'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','281','Amortissements des immobilisations incorporelles','2815','Amortissements du fonds commercial'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','281','Amortissements des immobilisations incorporelles','2816','Amortissements du droit au bail'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','281','Amortissements des immobilisations incorporelles','2817','Amortissements des investissements de création'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','281','Amortissements des immobilisations incorporelles','2818','Amortissements des autres droits et valeurs incorporels'],
[3,'2',"Comptes d'actif immobilisé",'28','Amortissements','282','Amortissements des terrains',null,null],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','282','Amortissements des terrains','2824','Amortissements des travaux de mise en valeur des terrains'],
[3,'2',"Comptes d'actif immobilisé",'28','Amortissements','283','Amortissements des bâtiments, installations techniques et agencements',null,null],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','283','Amortissements des bâtiments, installations techniques et agencements','2831','Amortissements des bâtiments sur sol propre'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','283','Amortissements des bâtiments, installations techniques et agencements','2832','Amortissements des bâtiments sur sol d\'autrui'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','283','Amortissements des bâtiments, installations techniques et agencements','2833',"Amortissements des ouvrages d'infrastructure"],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','283','Amortissements des bâtiments, installations techniques et agencements','2834','Amortissements des aménagements, agencements et installations techniques'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','283','Amortissements des bâtiments, installations techniques et agencements','2835','Amortissements des aménagements de bureaux'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','283','Amortissements des bâtiments, installations techniques et agencements','2838','Amortissements des autres installations et agencements'],
[3,'2',"Comptes d'actif immobilisé",'28','Amortissements','284','Amortissements du matériel',null,null],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','284','Amortissements du matériel','2841','Amortissements du matériel et outillage industriel et commercial'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','284','Amortissements du matériel','2842','Amortissements du matériel et outillage agricole'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','284','Amortissements du matériel','2843',"Amortissements du matériel d'emballage"],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','284','Amortissements du matériel','2844','Amortissements du matériel et mobilier'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','284','Amortissements du matériel','2845','Amortissements du matériel de transport'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','284','Amortissements du matériel','2846','Amortissements des actifs biologiques'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','284','Amortissements du matériel','2847','Amortissements des agencements et aménagements du matériel'],
[4,'2',"Comptes d'actif immobilisé",'28','Amortissements','284','Amortissements du matériel','2848','Amortissements des autres matériels'],
// 29 Dépréciations
[2,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations',null,null,null,null],
[3,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','291','Dépréciations des immobilisations incorporelles',null,null],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','291','Dépréciations des immobilisations incorporelles','2911','Dépréciations des frais de développement'],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','291','Dépréciations des immobilisations incorporelles','2912','Dépréciations des brevets, licences, concessions et droits similaires'],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','291','Dépréciations des immobilisations incorporelles','2913','Dépréciations des logiciels et sites internet'],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','291','Dépréciations des immobilisations incorporelles','2914','Dépréciations des marques'],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','291','Dépréciations des immobilisations incorporelles','2915','Dépréciations du fonds commercial'],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','291','Dépréciations des immobilisations incorporelles','2918','Dépréciations des autres droits et valeurs incorporels'],
[3,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','292','Dépréciations des terrains',null,null],
[3,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','293','Dépréciations des bâtiments, installations techniques et agencements',null,null],
[3,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','294','Dépréciations de matériel, du mobilier et de l\'actif biologique',null,null],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','294','Dépréciations de matériel, du mobilier et de l\'actif biologique','2941','Dépréciations du matériel et outillage industriel et commercial'],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','294','Dépréciations de matériel, du mobilier et de l\'actif biologique','2942','Dépréciations du matériel et outillage agricole'],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','294','Dépréciations de matériel, du mobilier et de l\'actif biologique','2944','Dépréciations du matériel et mobilier'],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','294','Dépréciations de matériel, du mobilier et de l\'actif biologique','2945','Dépréciations du matériel de transport'],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','294','Dépréciations de matériel, du mobilier et de l\'actif biologique','2946','Dépréciations des actifs biologiques'],
[4,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','294','Dépréciations de matériel, du mobilier et de l\'actif biologique','2948','Dépréciations des autres matériels'],
[3,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','295','Dépréciations des avances et acomptes versés sur immobilisations',null,null],
[3,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','296','Dépréciations des titres de participation',null,null],
[3,'2',"Comptes d'actif immobilisé",'29','Dépréciations des immobilisations','297','Dépréciations des autres immobilisations financières',null,null],

// ══════════════════════════════════════════════════════════════════════════════
// CLASSE 3 — Comptes de stocks
// ══════════════════════════════════════════════════════════════════════════════
[1,'3','Comptes de stocks',null,null,null,null,null,null],
[2,'3','Comptes de stocks','31','Marchandises',null,null,null,null],
[3,'3','Comptes de stocks','31','Marchandises','311','Marchandises A',null,null],
[3,'3','Comptes de stocks','31','Marchandises','312','Marchandises B',null,null],
[3,'3','Comptes de stocks','31','Marchandises','313','Actifs biologiques',null,null],
[3,'3','Comptes de stocks','31','Marchandises','318','Marchandises hors activités ordinaires (HAO)',null,null],
[2,'3','Comptes de stocks','32','Matières premières et fournitures liées',null,null,null,null],
[3,'3','Comptes de stocks','32','Matières premières et fournitures liées','321','Matières A',null,null],
[3,'3','Comptes de stocks','32','Matières premières et fournitures liées','322','Matières B',null,null],
[3,'3','Comptes de stocks','32','Matières premières et fournitures liées','323','Fournitures (A, B)',null,null],
[2,'3','Comptes de stocks','33','Autres approvisionnements',null,null,null,null],
[3,'3','Comptes de stocks','33','Autres approvisionnements','331','Matières consommables',null,null],
[3,'3','Comptes de stocks','33','Autres approvisionnements','332',"Fournitures d'atelier et d'usine",null,null],
[3,'3','Comptes de stocks','33','Autres approvisionnements','333','Fournitures de magasin',null,null],
[3,'3','Comptes de stocks','33','Autres approvisionnements','334','Fournitures de bureau',null,null],
[3,'3','Comptes de stocks','33','Autres approvisionnements','335','Emballages',null,null],
[2,'3','Comptes de stocks','34','Produits en cours',null,null,null,null],
[3,'3','Comptes de stocks','34','Produits en cours','341','Produits en cours',null,null],
[3,'3','Comptes de stocks','34','Produits en cours','342','Travaux en cours',null,null],
[2,'3','Comptes de stocks','35','Services en cours',null,null,null,null],
[2,'3','Comptes de stocks','36','Produits finis',null,null,null,null],
[3,'3','Comptes de stocks','36','Produits finis','361','Produits finis A',null,null],
[3,'3','Comptes de stocks','36','Produits finis','362','Produits finis B',null,null],
[2,'3','Comptes de stocks','37','Produits intermédiaires et résiduels',null,null,null,null],
[2,'3','Comptes de stocks','38','Stocks en cours de route, en consignation ou en dépôt',null,null,null,null],
[2,'3','Comptes de stocks','39','Dépréciations des stocks et encours de production',null,null,null,null],
[3,'3','Comptes de stocks','39','Dépréciations des stocks et encours de production','391','Dépréciations des stocks de marchandises',null,null],
[3,'3','Comptes de stocks','39','Dépréciations des stocks et encours de production','392','Dépréciations des stocks de matières premières et fournitures liées',null,null],
[3,'3','Comptes de stocks','39','Dépréciations des stocks et encours de production','396','Dépréciations des stocks de produits finis',null,null],

// ══════════════════════════════════════════════════════════════════════════════
// CLASSE 4 — Comptes de tiers
// ══════════════════════════════════════════════════════════════════════════════
[1,'4','Comptes de tiers',null,null,null,null,null,null],
[2,'4','Comptes de tiers','40','Fournisseurs et comptes rattachés',null,null,null,null],
[3,'4','Comptes de tiers','40','Fournisseurs et comptes rattachés','401','Fournisseurs, dettes en compte',null,null],
[4,'4','Comptes de tiers','40','Fournisseurs et comptes rattachés','401','Fournisseurs, dettes en compte','4011','Fournisseurs'],
[4,'4','Comptes de tiers','40','Fournisseurs et comptes rattachés','401','Fournisseurs, dettes en compte','4012','Fournisseurs groupe'],
[4,'4','Comptes de tiers','40','Fournisseurs et comptes rattachés','401','Fournisseurs, dettes en compte','4013','Fournisseurs sous-traitants'],
[3,'4','Comptes de tiers','40','Fournisseurs et comptes rattachés','402','Fournisseurs, effets à payer',null,null],
[3,'4','Comptes de tiers','40','Fournisseurs et comptes rattachés','404',"Fournisseurs, acquisitions courantes d'immobilisations",null,null],
[3,'4','Comptes de tiers','40','Fournisseurs et comptes rattachés','408','Fournisseurs, factures non parvenues',null,null],
[3,'4','Comptes de tiers','40','Fournisseurs et comptes rattachés','409','Fournisseurs débiteurs',null,null],
[2,'4','Comptes de tiers','41','Clients et comptes rattachés',null,null,null,null],
[3,'4','Comptes de tiers','41','Clients et comptes rattachés','411','Clients',null,null],
[4,'4','Comptes de tiers','41','Clients et comptes rattachés','411','Clients','4111','Clients'],
[4,'4','Comptes de tiers','41','Clients et comptes rattachés','411','Clients','4112','Clients - groupe'],
[4,'4','Comptes de tiers','41','Clients et comptes rattachés','411','Clients','4116','Clients, réserve de propriété'],
[3,'4','Comptes de tiers','41','Clients et comptes rattachés','412','Clients, effets à recevoir en portefeuille',null,null],
[3,'4','Comptes de tiers','41','Clients et comptes rattachés','416','Créances clients litigieuses ou douteuses',null,null],
[3,'4','Comptes de tiers','41','Clients et comptes rattachés','418','Clients, produits à recevoir',null,null],
[3,'4','Comptes de tiers','41','Clients et comptes rattachés','419','Clients créditeurs',null,null],
[2,'4','Comptes de tiers','42','Personnel',null,null,null,null],
[3,'4','Comptes de tiers','42','Personnel','421','Personnel, avances et acomptes',null,null],
[3,'4','Comptes de tiers','42','Personnel','422','Personnel, rémunérations dues',null,null],
[3,'4','Comptes de tiers','42','Personnel','428','Personnel, charges à payer et produits à recevoir',null,null],
[4,'4','Comptes de tiers','42','Personnel','428','Personnel, charges à payer et produits à recevoir','4281','Dettes provisionnées pour congés à payer'],
[2,'4','Comptes de tiers','43','Organismes sociaux',null,null,null,null],
[3,'4','Comptes de tiers','43','Organismes sociaux','431','Sécurité sociale',null,null],
[2,'4','Comptes de tiers','44','État et collectivités publiques',null,null,null,null],
[3,'4','Comptes de tiers','44','État et collectivités publiques','441',"État, impôt sur les bénéfices",null,null],
[3,'4','Comptes de tiers','44','État et collectivités publiques','442','État, autres impôts et taxes',null,null],
[3,'4','Comptes de tiers','44','État et collectivités publiques','443','État, TVA facturée',null,null],
[4,'4','Comptes de tiers','44','État et collectivités publiques','443','État, TVA facturée','4431','TVA facturée sur ventes'],
[4,'4','Comptes de tiers','44','État et collectivités publiques','443','État, TVA facturée','4432','TVA facturée sur prestations de services'],
[3,'4','Comptes de tiers','44','État et collectivités publiques','444','État, TVA due ou crédit de TVA',null,null],
[4,'4','Comptes de tiers','44','État et collectivités publiques','444','État, TVA due ou crédit de TVA','4441','État, TVA due'],
[4,'4','Comptes de tiers','44','État et collectivités publiques','444','État, TVA due ou crédit de TVA','4449','État, crédit de TVA à reporter'],
[3,'4','Comptes de tiers','44','État et collectivités publiques','445','État, TVA récupérable',null,null],
[4,'4','Comptes de tiers','44','État et collectivités publiques','445','État, TVA récupérable','4451','TVA récupérable sur immobilisations'],
[4,'4','Comptes de tiers','44','État et collectivités publiques','445','État, TVA récupérable','4452','TVA récupérable sur achats'],
[4,'4','Comptes de tiers','44','État et collectivités publiques','445','État, TVA récupérable','4454','TVA récupérable sur services extérieurs et autres charges'],
[3,'4','Comptes de tiers','44','État et collectivités publiques','447',"État, impôts retenus à la source",null,null],
[3,'4','Comptes de tiers','44','État et collectivités publiques','449',"État, créances et dettes diverses",null,null],
[2,'4','Comptes de tiers','47','Débiteurs et créditeurs divers',null,null,null,null],
[3,'4','Comptes de tiers','47','Débiteurs et créditeurs divers','471','Débiteurs et créditeurs divers',null,null],
[3,'4','Comptes de tiers','47','Débiteurs et créditeurs divers','476','Charges constatées d\'avance',null,null],
[3,'4','Comptes de tiers','47','Débiteurs et créditeurs divers','477','Produits constatés d\'avance',null,null],
[3,'4','Comptes de tiers','47','Débiteurs et créditeurs divers','478',"Écarts de conversion - actif",null,null],
[4,'4','Comptes de tiers','47','Débiteurs et créditeurs divers','478',"Écarts de conversion - actif",'4781',"Diminution des créances d'exploitation"],
[4,'4','Comptes de tiers','47','Débiteurs et créditeurs divers','478',"Écarts de conversion - actif",'4783',"Augmentation des dettes d'exploitation"],
[3,'4','Comptes de tiers','47','Débiteurs et créditeurs divers','479',"Écarts de conversion - passif",null,null],
[4,'4','Comptes de tiers','47','Débiteurs et créditeurs divers','479',"Écarts de conversion - passif",'4791',"Augmentation des créances d'exploitation"],
[4,'4','Comptes de tiers','47','Débiteurs et créditeurs divers','479',"Écarts de conversion - passif",'4793',"Diminution des dettes d'exploitation"],
[2,'4','Comptes de tiers','48','Créances et dettes hors activités ordinaires (HAO)',null,null,null,null],
[3,'4','Comptes de tiers','48','Créances et dettes hors activités ordinaires (HAO)','481',"Fournisseurs d'investissements",null,null],
[3,'4','Comptes de tiers','48','Créances et dettes hors activités ordinaires (HAO)','485',"Créances sur cessions d'immobilisations",null,null],
[2,'4','Comptes de tiers','49','Dépréciations et provisions pour risques à court terme (tiers)',null,null,null,null],
[3,'4','Comptes de tiers','49','Dépréciations et provisions pour risques à court terme (tiers)','491','Dépréciations des comptes clients',null,null],

// ══════════════════════════════════════════════════════════════════════════════
// CLASSE 5 — Comptes de trésorerie
// ══════════════════════════════════════════════════════════════════════════════
[1,'5','Comptes de trésorerie',null,null,null,null,null,null],
[2,'5','Comptes de trésorerie','50','Titres de placement',null,null,null,null],
[3,'5','Comptes de trésorerie','50','Titres de placement','502','Actions',null,null],
[3,'5','Comptes de trésorerie','50','Titres de placement','503','Obligations',null,null],
[2,'5','Comptes de trésorerie','51','Valeurs à encaisser',null,null,null,null],
[3,'5','Comptes de trésorerie','51','Valeurs à encaisser','511','Effets à encaisser',null,null],
[3,'5','Comptes de trésorerie','51','Valeurs à encaisser','513','Chèques à encaisser',null,null],
[2,'5','Comptes de trésorerie','52','Banques',null,null,null,null],
[3,'5','Comptes de trésorerie','52','Banques','521','Banques locales',null,null],
[4,'5','Comptes de trésorerie','52','Banques','521','Banques locales','5211','Banques en monnaie nationale'],
[4,'5','Comptes de trésorerie','52','Banques','521','Banques locales','5215','Banques en devises'],
[3,'5','Comptes de trésorerie','52','Banques','525','Banques dépôt à terme',null,null],
[2,'5','Comptes de trésorerie','53','Établissements financiers et assimilés',null,null,null,null],
[3,'5','Comptes de trésorerie','53','Établissements financiers et assimilés','531','Chèques postaux',null,null],
[3,'5','Comptes de trésorerie','53','Établissements financiers et assimilés','532','Trésor',null,null],
[2,'5','Comptes de trésorerie','55','Instruments de monnaie électronique',null,null,null,null],
[3,'5','Comptes de trésorerie','55','Instruments de monnaie électronique','552','Monnaie électronique - téléphone portable',null,null],
[3,'5','Comptes de trésorerie','55','Instruments de monnaie électronique','554','Porte-monnaie électronique',null,null],
[2,'5','Comptes de trésorerie','56','Banques, crédits de trésorerie et d\'escompte',null,null,null,null],
[3,'5','Comptes de trésorerie','56','Banques, crédits de trésorerie et d\'escompte','561','Crédits de trésorerie',null,null],
[3,'5','Comptes de trésorerie','56','Banques, crédits de trésorerie et d\'escompte','565','Escompte de crédits ordinaires',null,null],
[2,'5','Comptes de trésorerie','57','Caisse',null,null,null,null],
[3,'5','Comptes de trésorerie','57','Caisse','571','Caisse siège social',null,null],
[4,'5','Comptes de trésorerie','57','Caisse','571','Caisse siège social','5711','Caisse en monnaie nationale'],
[4,'5','Comptes de trésorerie','57','Caisse','571','Caisse siège social','5712','Caisse en devises'],
[2,'5','Comptes de trésorerie','58','Régies d\'avances, accréditifs et virements internes',null,null,null,null],
[3,'5','Comptes de trésorerie','58','Régies d\'avances, accréditifs et virements internes','585','Virements de fonds',null,null],
[2,'5','Comptes de trésorerie','59','Dépréciations et provisions pour risque à court terme',null,null,null,null],
[3,'5','Comptes de trésorerie','59','Dépréciations et provisions pour risque à court terme','590','Dépréciations des titres de placement',null,null],

// ══════════════════════════════════════════════════════════════════════════════
// CLASSE 6 — Comptes de charges des activités ordinaires
// ══════════════════════════════════════════════════════════════════════════════
[1,'6','Comptes de charges des activités ordinaires',null,null,null,null,null,null],
[2,'6','Comptes de charges des activités ordinaires','60','Achats et variations de stocks',null,null,null,null],
[3,'6','Comptes de charges des activités ordinaires','60','Achats et variations de stocks','601','Achats de marchandises',null,null],
[3,'6','Comptes de charges des activités ordinaires','60','Achats et variations de stocks','602','Achats de matières premières et fournitures liées',null,null],
[3,'6','Comptes de charges des activités ordinaires','60','Achats et variations de stocks','604','Achats stockés de matières et fournitures consommables',null,null],
[3,'6','Comptes de charges des activités ordinaires','60','Achats et variations de stocks','605','Autres achats',null,null],
[4,'6','Comptes de charges des activités ordinaires','60','Achats et variations de stocks','605','Autres achats','6051','Fournitures non stockables - Eau'],
[4,'6','Comptes de charges des activités ordinaires','60','Achats et variations de stocks','605','Autres achats','6052',"Fournitures non stockables - Électricité"],
[4,'6','Comptes de charges des activités ordinaires','60','Achats et variations de stocks','605','Autres achats','6053','Fournitures non stockables - Autres énergies'],
[2,'6','Comptes de charges des activités ordinaires','61','Transports',null,null,null,null],
[3,'6','Comptes de charges des activités ordinaires','61','Transports','612','Transports sur ventes',null,null],
[3,'6','Comptes de charges des activités ordinaires','61','Transports','614','Transports du personnel',null,null],
[3,'6','Comptes de charges des activités ordinaires','61','Transports','618','Autres frais de transport',null,null],
[4,'6','Comptes de charges des activités ordinaires','61','Transports','618','Autres frais de transport','6181','Voyages et déplacements'],
[2,'6','Comptes de charges des activités ordinaires','62','Services extérieurs',null,null,null,null],
[3,'6','Comptes de charges des activités ordinaires','62','Services extérieurs','621','Sous-traitance générale',null,null],
[3,'6','Comptes de charges des activités ordinaires','62','Services extérieurs','622','Locations, charges locatives',null,null],
[3,'6','Comptes de charges des activités ordinaires','62','Services extérieurs','623','Redevances de location acquisition',null,null],
[4,'6','Comptes de charges des activités ordinaires','62','Services extérieurs','623','Redevances de location acquisition','6232','Crédit-bail immobilier'],
[4,'6','Comptes de charges des activités ordinaires','62','Services extérieurs','623','Redevances de location acquisition','6233','Crédit-bail mobilier'],
[3,'6','Comptes de charges des activités ordinaires','62','Services extérieurs','624','Entretien, réparations, remise en état et maintenance',null,null],
[3,'6','Comptes de charges des activités ordinaires','62','Services extérieurs','625',"Primes d'assurance",null,null],
[3,'6','Comptes de charges des activités ordinaires','62','Services extérieurs','628','Frais de télécommunications',null,null],
[2,'6','Comptes de charges des activités ordinaires','63','Autres services extérieurs',null,null,null,null],
[3,'6','Comptes de charges des activités ordinaires','63','Autres services extérieurs','631','Frais bancaires',null,null],
[3,'6','Comptes de charges des activités ordinaires','63','Autres services extérieurs','632',"Rémunérations d'intermédiaires et de conseils",null,null],
[3,'6','Comptes de charges des activités ordinaires','63','Autres services extérieurs','633','Frais de formation du personnel',null,null],
[3,'6','Comptes de charges des activités ordinaires','63','Autres services extérieurs','634','Redevances pour brevets, licences, logiciels, concessions et droits et valeurs similaires',null,null],
[3,'6','Comptes de charges des activités ordinaires','63','Autres services extérieurs','638','Autres charges externes',null,null],
[2,'6','Comptes de charges des activités ordinaires','64','Impôts et taxes',null,null,null,null],
[3,'6','Comptes de charges des activités ordinaires','64','Impôts et taxes','641','Impôts et taxes directs',null,null],
[3,'6','Comptes de charges des activités ordinaires','64','Impôts et taxes','645','Impôts et taxes indirects',null,null],
[3,'6','Comptes de charges des activités ordinaires','64','Impôts et taxes','647','Pénalités, amendes fiscales',null,null],
[2,'6','Comptes de charges des activités ordinaires','65','Autres charges',null,null,null,null],
[3,'6','Comptes de charges des activités ordinaires','65','Autres charges','651','Pertes sur créances clients et autres débiteurs',null,null],
[3,'6','Comptes de charges des activités ordinaires','65','Autres charges','654','Valeurs comptables des cessions courantes d\'immobilisations',null,null],
[4,'6','Comptes de charges des activités ordinaires','65','Autres charges','654','Valeurs comptables des cessions courantes d\'immobilisations','6541','Immobilisations incorporelles'],
[4,'6','Comptes de charges des activités ordinaires','65','Autres charges','654','Valeurs comptables des cessions courantes d\'immobilisations','6542','Immobilisations corporelles'],
[3,'6','Comptes de charges des activités ordinaires','65','Autres charges','658','Charges diverses',null,null],
[3,'6','Comptes de charges des activités ordinaires','65','Autres charges','659','Charges pour dépréciations et provisions pour risques à court terme d\'exploitation',null,null],
[2,'6','Comptes de charges des activités ordinaires','66','Charges de personnel',null,null,null,null],
[3,'6','Comptes de charges des activités ordinaires','66','Charges de personnel','661','Rémunérations directes versées au personnel national',null,null],
[4,'6','Comptes de charges des activités ordinaires','66','Charges de personnel','661','Rémunérations directes versées au personnel national','6611','Appointements salaires et commissions'],
[4,'6','Comptes de charges des activités ordinaires','66','Charges de personnel','661','Rémunérations directes versées au personnel national','6612','Primes et gratifications'],
[4,'6','Comptes de charges des activités ordinaires','66','Charges de personnel','661','Rémunérations directes versées au personnel national','6613','Congés payés'],
[3,'6','Comptes de charges des activités ordinaires','66','Charges de personnel','664','Charges sociales',null,null],
[4,'6','Comptes de charges des activités ordinaires','66','Charges de personnel','664','Charges sociales','6641','Charges sociales sur rémunération du personnel national'],
[2,'6','Comptes de charges des activités ordinaires','67','Frais financiers et charges assimilées',null,null,null,null],
[3,'6','Comptes de charges des activités ordinaires','67','Frais financiers et charges assimilées','671','Intérêts des emprunts',null,null],
[3,'6','Comptes de charges des activités ordinaires','67','Frais financiers et charges assimilées','673','Escomptes accordés',null,null],
[3,'6','Comptes de charges des activités ordinaires','67','Frais financiers et charges assimilées','676','Pertes de change financières',null,null],
[3,'6','Comptes de charges des activités ordinaires','67','Frais financiers et charges assimilées','677','Pertes sur titres de placement',null,null],
[2,'6','Comptes de charges des activités ordinaires','68','Dotations aux amortissements',null,null,null,null],
[3,'6','Comptes de charges des activités ordinaires','68','Dotations aux amortissements','681','Dotations aux amortissements d\'exploitation',null,null],
[4,'6','Comptes de charges des activités ordinaires','68','Dotations aux amortissements','681','Dotations aux amortissements d\'exploitation','6812','Dotations aux amortissements des immobilisations incorporelles'],
[4,'6','Comptes de charges des activités ordinaires','68','Dotations aux amortissements','681','Dotations aux amortissements d\'exploitation','6813','Dotations aux amortissements des immobilisations corporelles'],
[2,'6','Comptes de charges des activités ordinaires','69','Dotations aux provisions et aux dépréciations',null,null,null,null],
[3,'6','Comptes de charges des activités ordinaires','69','Dotations aux provisions et aux dépréciations','691','Dotations aux provisions et aux dépréciations d\'exploitation',null,null],

// ══════════════════════════════════════════════════════════════════════════════
// CLASSE 7 — Comptes de produits des activités ordinaires
// ══════════════════════════════════════════════════════════════════════════════
[1,'7','Comptes de produits des activités ordinaires',null,null,null,null,null,null],
[2,'7','Comptes de produits des activités ordinaires','70','Ventes',null,null,null,null],
[3,'7','Comptes de produits des activités ordinaires','70','Ventes','701','Ventes de marchandises',null,null],
[3,'7','Comptes de produits des activités ordinaires','70','Ventes','702','Ventes de produits finis',null,null],
[3,'7','Comptes de produits des activités ordinaires','70','Ventes','705','Travaux facturés',null,null],
[3,'7','Comptes de produits des activités ordinaires','70','Ventes','706','Services vendus',null,null],
[3,'7','Comptes de produits des activités ordinaires','70','Ventes','707','Produits accessoires',null,null],
[2,'7','Comptes de produits des activités ordinaires','71',"Subventions d'exploitation",null,null,null,null],
[3,'7','Comptes de produits des activités ordinaires','71',"Subventions d'exploitation",'711','Sur produits à l\'exportation',null,null],
[3,'7','Comptes de produits des activités ordinaires','71',"Subventions d'exploitation",'718',"Autres subventions d'exploitation",null,null],
[2,'7','Comptes de produits des activités ordinaires','72','Production immobilisée',null,null,null,null],
[3,'7','Comptes de produits des activités ordinaires','72','Production immobilisée','721','Immobilisations incorporelles',null,null],
[3,'7','Comptes de produits des activités ordinaires','72','Production immobilisée','722','Immobilisations corporelles',null,null],
[2,'7','Comptes de produits des activités ordinaires','73','Variations des stocks de biens et de services produits',null,null,null,null],
[2,'7','Comptes de produits des activités ordinaires','75','Autres produits',null,null,null,null],
[3,'7','Comptes de produits des activités ordinaires','75','Autres produits','751','Profits sur créances clients et autres débiteurs',null,null],
[3,'7','Comptes de produits des activités ordinaires','75','Autres produits','754',"Produits des cessions courantes d'immobilisations",null,null],
[4,'7','Comptes de produits des activités ordinaires','75','Autres produits','754',"Produits des cessions courantes d'immobilisations",'7541','Immobilisations incorporelles'],
[4,'7','Comptes de produits des activités ordinaires','75','Autres produits','754',"Produits des cessions courantes d'immobilisations",'7542','Immobilisations corporelles'],
[3,'7','Comptes de produits des activités ordinaires','75','Autres produits','756','Gains de change sur créances et dettes commerciales',null,null],
[3,'7','Comptes de produits des activités ordinaires','75','Autres produits','758','Produits divers',null,null],
[3,'7','Comptes de produits des activités ordinaires','75','Autres produits','759','Reprises de charges pour dépréciations et provisions pour risques à court terme d\'exploitation',null,null],
[2,'7','Comptes de produits des activités ordinaires','77','Revenus financiers et produits assimilés',null,null,null,null],
[3,'7','Comptes de produits des activités ordinaires','77','Revenus financiers et produits assimilés','771','Intérêts de prêts et créances diverses',null,null],
[3,'7','Comptes de produits des activités ordinaires','77','Revenus financiers et produits assimilés','772','Revenus de participations et autres titres immobilisés',null,null],
[3,'7','Comptes de produits des activités ordinaires','77','Revenus financiers et produits assimilés','773','Escomptes obtenus',null,null],
[3,'7','Comptes de produits des activités ordinaires','77','Revenus financiers et produits assimilés','776','Gains de change financiers',null,null],
[3,'7','Comptes de produits des activités ordinaires','77','Revenus financiers et produits assimilés','779','Reprises de charges pour dépréciations et provisions à court terme financières',null,null],
[2,'7','Comptes de produits des activités ordinaires','78','Transferts de charges',null,null,null,null],
[3,'7','Comptes de produits des activités ordinaires','78','Transferts de charges','781',"Transferts de charges d'exploitation",null,null],
[2,'7','Comptes de produits des activités ordinaires','79','Reprises de provisions, de dépréciations et autres',null,null,null,null],
[3,'7','Comptes de produits des activités ordinaires','79','Reprises de provisions, de dépréciations et autres','791','Reprises de provisions et dépréciations d\'exploitation',null,null],
[3,'7','Comptes de produits des activités ordinaires','79','Reprises de provisions, de dépréciations et autres','797','Reprises de provisions et dépréciations financières',null,null],
[3,'7','Comptes de produits des activités ordinaires','79','Reprises de provisions, de dépréciations et autres','799',"Reprises de subventions d'investissement",null,null],

// ══════════════════════════════════════════════════════════════════════════════
// CLASSE 8 — Comptes des autres charges et des autres produits
// ══════════════════════════════════════════════════════════════════════════════
[1,'8','Comptes des autres charges et des autres produits',null,null,null,null,null,null],
[2,'8','Comptes des autres charges et des autres produits','81',"Valeurs comptables des cessions d'immobilisations",null,null,null,null],
[3,'8','Comptes des autres charges et des autres produits','81',"Valeurs comptables des cessions d'immobilisations",'811','Immobilisations incorporelles',null,null],
[3,'8','Comptes des autres charges et des autres produits','81',"Valeurs comptables des cessions d'immobilisations",'812','Immobilisations corporelles',null,null],
[3,'8','Comptes des autres charges et des autres produits','81',"Valeurs comptables des cessions d'immobilisations",'816','Immobilisations financières',null,null],
[2,'8','Comptes des autres charges et des autres produits','82',"Produits des cessions d'immobilisations",null,null,null,null],
[3,'8','Comptes des autres charges et des autres produits','82',"Produits des cessions d'immobilisations",'821','Immobilisations incorporelles',null,null],
[3,'8','Comptes des autres charges et des autres produits','82',"Produits des cessions d'immobilisations",'822','Immobilisations corporelles',null,null],
[3,'8','Comptes des autres charges et des autres produits','82',"Produits des cessions d'immobilisations",'826','Immobilisations financières',null,null],
[2,'8','Comptes des autres charges et des autres produits','83','Charges hors activités ordinaires',null,null,null,null],
[3,'8','Comptes des autres charges et des autres produits','83','Charges hors activités ordinaires','831','Charges HAO constatées',null,null],
[3,'8','Comptes des autres charges et des autres produits','83','Charges hors activités ordinaires','835','Dons et libéralités accordés',null,null],
[3,'8','Comptes des autres charges et des autres produits','83','Charges hors activités ordinaires','836','Abandons de créances consentis',null,null],
[2,'8','Comptes des autres charges et des autres produits','84','Produits hors activités ordinaires',null,null,null,null],
[3,'8','Comptes des autres charges et des autres produits','84','Produits hors activités ordinaires','841','Produits HAO constatés',null,null],
[3,'8','Comptes des autres charges et des autres produits','84','Produits hors activités ordinaires','845','Dons et libéralités obtenus',null,null],
[3,'8','Comptes des autres charges et des autres produits','84','Produits hors activités ordinaires','848','Transferts de charges HAO',null,null],
[2,'8','Comptes des autres charges et des autres produits','85','Dotations hors activités ordinaires',null,null,null,null],
[3,'8','Comptes des autres charges et des autres produits','85','Dotations hors activités ordinaires','851','Dotations aux provisions réglementées',null,null],
[3,'8','Comptes des autres charges et des autres produits','85','Dotations hors activités ordinaires','852','Dotations aux amortissements HAO',null,null],
[3,'8','Comptes des autres charges et des autres produits','85','Dotations hors activités ordinaires','853','Dotations aux dépréciations HAO',null,null],
[2,'8','Comptes des autres charges et des autres produits','86','Reprises hors activités ordinaires',null,null,null,null],
[3,'8','Comptes des autres charges et des autres produits','86','Reprises hors activités ordinaires','861','Reprises de provisions réglementées',null,null],
[3,'8','Comptes des autres charges et des autres produits','86','Reprises hors activités ordinaires','862','Reprises des amortissements HAO',null,null],
[3,'8','Comptes des autres charges et des autres produits','86','Reprises hors activités ordinaires','863','Reprises des dépréciations HAO',null,null],
[2,'8','Comptes des autres charges et des autres produits','88','Participation des travailleurs',null,null,null,null],
[3,'8','Comptes des autres charges et des autres produits','88','Participation des travailleurs','881','Participation des travailleurs',null,null],
[2,'8','Comptes des autres charges et des autres produits','89',"Impôts sur le résultat",null,null,null,null],
[3,'8','Comptes des autres charges et des autres produits','89',"Impôts sur le résultat",'891',"Impôts sur le résultat",null,null],
[3,'8','Comptes des autres charges et des autres produits','89',"Impôts sur le résultat",'892',"Impôts différés",null,null],
];

// ─── Insertion en batch ───────────────────────────────────────────────────────
$sql = "INSERT INTO ohada_plan_comptable
        (niveau, classe, libelle_classe, compte_2, libelle_2, compte_3, libelle_3, compte_4, libelle_4)
        VALUES (?,?,?,?,?,?,?,?,?)";
$stmt = $db->prepare($sql);

$db->beginTransaction();
$count = 0;
foreach ($rows as $r) {
    $stmt->execute($r);
    $count++;
}
$db->commit();

// ─── Complétion automatique des niveaux manquants ─────────────────────────────
// Règle 1 : tout compte niveau=3 sans aucun niveau=4 → créer compte_4 = compte_3.'1'
// Règle 2 : tout compte niveau=2 sans aucun niveau=3 → créer compte_3 = compte_2.'1'
//           ET compte_4 = compte_2.'11'

$countAuto = 0;
$stmtInsert = $db->prepare("
    INSERT INTO ohada_plan_comptable
        (niveau, classe, libelle_classe, compte_2, libelle_2, compte_3, libelle_3, compte_4, libelle_4)
    VALUES (?,?,?,?,?,?,?,?,?)
");

// ── Règle 1 : niveau=3 sans enfants niveau=4 ─────────────────────────────────
$n3 = $db->query("
    SELECT * FROM ohada_plan_comptable
    WHERE niveau = 3
    AND compte_3 NOT IN (
        SELECT DISTINCT compte_3 FROM ohada_plan_comptable WHERE niveau = 4 AND compte_3 IS NOT NULL
    )
")->fetchAll(PDO::FETCH_ASSOC);

$db->beginTransaction();
foreach ($n3 as $r) {
    $stmtInsert->execute([
        4,
        $r['classe'], $r['libelle_classe'],
        $r['compte_2'], $r['libelle_2'],
        $r['compte_3'], $r['libelle_3'],
        $r['compte_3'] . '1',   // compte_4 = compte_3 + '1'
        $r['libelle_3'],         // même libellé
    ]);
    $countAuto++;
}
$db->commit();

// ── Règle 2 : niveau=2 sans enfants niveau=3 ─────────────────────────────────
$n2 = $db->query("
    SELECT * FROM ohada_plan_comptable
    WHERE niveau = 2
    AND compte_2 NOT IN (
        SELECT DISTINCT compte_2 FROM ohada_plan_comptable WHERE niveau = 3 AND compte_2 IS NOT NULL
    )
")->fetchAll(PDO::FETCH_ASSOC);

$db->beginTransaction();
foreach ($n2 as $r) {
    $c3 = $r['compte_2'] . '1';
    $c4 = $r['compte_2'] . '11';
    // Créer niveau=3
    $stmtInsert->execute([
        3,
        $r['classe'], $r['libelle_classe'],
        $r['compte_2'], $r['libelle_2'],
        $c3, $r['libelle_2'],
        null, null,
    ]);
    // Créer niveau=4
    $stmtInsert->execute([
        4,
        $r['classe'], $r['libelle_classe'],
        $r['compte_2'], $r['libelle_2'],
        $c3, $r['libelle_2'],
        $c4, $r['libelle_2'],
    ]);
    $countAuto += 2;
}
$db->commit();

// ─── Correspondance bd/bc/rd/rc depuis table_correspondance ──────────────────

// Étape 1 : correspondance directe niveau=4 ↔ table_correspondance.compte (4 chiffres)
$db->exec("
    UPDATE ohada_plan_comptable o
    INNER JOIN table_correspondance tc ON tc.compte = o.compte_4
    SET o.bd = tc.bd, o.bc = tc.bc, o.rd = tc.rd, o.rc = tc.rc
    WHERE o.niveau = 4
");

// Étape 2 : propagation niveau=4 → niveau=3
// Pour chaque compte_3, prend les rubriques du premier niveau=4 trouvé
$db->exec("
    UPDATE ohada_plan_comptable o3
    INNER JOIN (
        SELECT compte_3,
               MIN(bd) AS bd, MIN(bc) AS bc, MIN(rd) AS rd, MIN(rc) AS rc
        FROM ohada_plan_comptable
        WHERE niveau = 4
          AND compte_3 IS NOT NULL
          AND (bd IS NOT NULL OR bc IS NOT NULL OR rd IS NOT NULL OR rc IS NOT NULL)
        GROUP BY compte_3
    ) sub ON sub.compte_3 = o3.compte_3
    SET o3.bd = sub.bd, o3.bc = sub.bc, o3.rd = sub.rd, o3.rc = sub.rc
    WHERE o3.niveau = 3
      AND o3.bd IS NULL
");

// Étape 3 : propagation niveau=3 → niveau=2
$db->exec("
    UPDATE ohada_plan_comptable o2
    INNER JOIN (
        SELECT compte_2,
               MIN(bd) AS bd, MIN(bc) AS bc, MIN(rd) AS rd, MIN(rc) AS rc
        FROM ohada_plan_comptable
        WHERE niveau = 3
          AND compte_2 IS NOT NULL
          AND (bd IS NOT NULL OR bc IS NOT NULL OR rd IS NOT NULL OR rc IS NOT NULL)
        GROUP BY compte_2
    ) sub ON sub.compte_2 = o2.compte_2
    SET o2.bd = sub.bd, o2.bc = sub.bc, o2.rd = sub.rd, o2.rc = sub.rc
    WHERE o2.niveau = 2
      AND o2.bd IS NULL
");

// Étape 4 : propagation niveau=2 → niveau=1
$db->exec("
    UPDATE ohada_plan_comptable o1
    INNER JOIN (
        SELECT classe,
               MIN(bd) AS bd, MIN(bc) AS bc, MIN(rd) AS rd, MIN(rc) AS rc
        FROM ohada_plan_comptable
        WHERE niveau = 2
          AND (bd IS NOT NULL OR bc IS NOT NULL OR rd IS NOT NULL OR rc IS NOT NULL)
        GROUP BY classe
    ) sub ON sub.classe = o1.classe
    SET o1.bd = sub.bd, o1.bc = sub.bc, o1.rd = sub.rd, o1.rc = sub.rc
    WHERE o1.niveau = 1
      AND o1.bd IS NULL
");

// Compter les comptes avec correspondance
$countCorrespondances = (int)$db->query("
    SELECT COUNT(*) FROM ohada_plan_comptable WHERE bd IS NOT NULL OR bc IS NOT NULL OR rd IS NOT NULL OR rc IS NOT NULL
")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Migration — Référentiel OHADA</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 p-8">
    <div class="max-w-2xl mx-auto">
        <div class="bg-emerald-900/30 border border-emerald-700 rounded-xl p-6">
            <h1 class="text-xl font-bold text-emerald-400 mb-2">
                ✅ Référentiel OHADA créé avec succès
            </h1>
            <p class="text-slate-300 mb-4">
                Table <code class="bg-slate-800 px-1 rounded">ohada_plan_comptable</code> créée et peuplée.
            </p>
            <div class="grid grid-cols-2 gap-4 text-sm mb-4">
                <div class="bg-slate-800 rounded-lg p-3">
                    <p class="text-slate-400">Lignes explicites</p>
                    <p class="text-2xl font-bold text-emerald-400"><?= $count ?></p>
                </div>
                <div class="bg-slate-800 rounded-lg p-3">
                    <p class="text-slate-400">Niveaux auto-complétés</p>
                    <p class="text-2xl font-bold text-sky-400"><?= $countAuto ?></p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div class="bg-slate-800 rounded-lg p-3">
                    <p class="text-slate-400">Total en base</p>
                    <p class="text-2xl font-bold text-violet-400"><?= $count + $countAuto ?></p>
                </div>
                <div class="bg-slate-800 rounded-lg p-3">
                    <p class="text-slate-400">Comptes avec bd/bc/rd/rc</p>
                    <p class="text-2xl font-bold text-amber-400"><?= $countCorrespondances ?></p>
                </div>
            </div>
            <div class="mt-4 bg-slate-800 rounded-lg p-4 text-xs font-mono text-slate-400">
                <p>Requête exemple :</p>
                <p class="text-sky-300 mt-1">SELECT libelle_3 FROM ohada_plan_comptable</p>
                <p class="text-sky-300">WHERE compte_3 = '244' AND niveau = 3</p>
                <p class="text-emerald-300 mt-1">→ "Matériel et mobilier"</p>
            </div>
        </div>
    </div>
</body>
</html>
