<?IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/bedrosova.ymlimport/import_setup_templ.php');

global $USER;
global $APPLICATION;

//перекодируем в ту кодировку, в которую надо, если надо
//Почему не отталкиваемся от кодировки сайта?
//Так было раньше, но по просьбам пользователей теперь мы вынесли перекодировку в настройки
function yml_iconv($str, $oe = "N")
{
	switch ($oe) {
		case "N":
			return $str;
		case "WU":
			return iconv("windows-1251", "utf-8", $str);
		case "UW":
			return iconv("utf-8", "windows-1251", $str);
		default:
			return $str;
		}	
}

class CYmlImport
{

    protected $arYmlCatalogProps = array();
    protected $isAllPropValsInOneProp = false; // Хранить ли все свойства товара в одном свойстве инфоблока
    protected $ymlPropPrefix = 'YML_'; // Префикс для колов импортируемых свойств
    protected $iblockId;
    protected $isNeedStores = false; // Нужно ли сохранять количество товаров в складах

    var $bTmpUserCreated = false;
    var $strImportErrorMessage = "";
    var $strImportOKMessage = "";
    var $max_execution_time = 0;
	var $price_modifier = 1.0;
    var $AllLinesLoaded = true;
    var $FILE_POS=0;

    var $fp;
	


    function file_get_contents($filename)
    {
        $fd = fopen("$filename", "rb");
        $content = fread($fd, filesize($filename));
        fclose($fd);
        return $content;
    }


    function CSVCheckTimeout($max_execution_time)
    {
        return ($max_execution_time <= 0) || (getmicrotime()-START_EXEC_TIME <= $max_execution_time);
    }


    // получаем xml-объект
    // смысл в том, что нельзя определить кодировку файла. всегда определяется utf-8
    // поэтому открываем файл как есть, если он объект создается - хорошо
    // если не создается - перекодируем и опять создаем.
    // ну уж если и тут не создался, я уже не знаю что делать
    function GetXMLObject($FilePath)
    {
        // содержимое
        $file_content = file_get_contents ($FilePath);

        // пытаемся получить объект
        $xml =  simplexml_load_string($file_content);
        if (!is_object($xml->shop))
        {
            //не могу создать объект
            $file_content = iconv("windows-1251", "utf-8", $file_content);

            // кодировка произведена
            // еще разик
            $xml =  simplexml_load_string($file_content);
        }

        return $xml;
    }


    // а это жирная функция как раз все делает
    function ImportYML ($DATA_FILE_NAME, $IBLOCK_ID, $IMPORT_CATEGORY, $ONLY_PRICE, $max_execution_time, $CUR_FILE_POS, $IMPORT_CATEGORY_SECTION, $URL_DATA_FILE2, $ID_SECTION, $CAT_FILTER_I, $price_modifier, $OPTION_ENCODING, $IS_IN_ONE_PROP, $ONE_PROP_CODE, $DIFF_PROP_CODE_PREFIX, $arSTORES)
    {

        if (!isset($USER) || !(($USER instanceof CUser) && ('CUser' == get_class($USER)))) {
            $bTmpUserCreated = true;
            if (isset($USER)) {
                $USER_TMP = $USER;
                unset($USER);
            }

            $USER = new CUser();
        }


        if ($max_execution_time <= 0)
            $max_execution_time = 0;
        if (defined('BX_CAT_CRON') && true == BX_CAT_CRON)
            $max_execution_time = 0;

        if (defined("CATALOG_LOAD_NO_STEP") && CATALOG_LOAD_NO_STEP)
            $max_execution_time = 0;

        $bAllLinesLoaded = true;


        if (strlen($URL_DATA_FILE) > 0) {
            $URL_DATA_FILE = Rel2Abs("/", $URL_DATA_FILE);
            if (file_exists($_SERVER["DOCUMENT_ROOT"].$URL_DATA_FILE) && is_file($_SERVER["DOCUMENT_ROOT"].$URL_DATA_FILE))
                $DATA_FILE_NAME = $URL_DATA_FILE;
        }
		
		if (!(strlen($DATA_FILE_NAME) > 0)) {
		    $DATA_FILE_NAME = $URL_DATA_FILE2;
		}

        //if (strlen($DATA_FILE_NAME) <= 0)
         //   $strImportErrorMessage .= GetMessage("CATI_NO_DATA_FILE")."<br>";

        $IBLOCK_ID = intval($IBLOCK_ID);
        if ($IBLOCK_ID <= 0) {
            $strImportErrorMessage .= GetMessage("CATI_NO_IBLOCK") . "<br>";
        } else {
            $this->iblockId = $IBLOCK_ID;
            $arIBlock = CIBlock::GetArrayByID($IBLOCK_ID);
            if (false === $arIBlock) {
                $strImportErrorMessage .= GetMessage("CATI_NO_IBLOCK") . "<br>";
            }
        }

        if (isset($IS_IN_ONE_PROP) && $IS_IN_ONE_PROP == 'Y') {
            $this->isAllPropValsInOneProp = true;
        } else {
            $this->ymlPropPrefix = (isset($DIFF_PROP_CODE_PREFIX) && !empty($DIFF_PROP_CODE_PREFIX)) ? $DIFF_PROP_CODE_PREFIX : 'YML_';
        }

        if (isset($arSTORES) && is_array($arSTORES) && count($arSTORES) == 1 && $arSTORES[0] == 'NOT_REF') {
            $this->isNeedStores = false;
        } else {
            $this->isNeedStores = true;
            $arSelStores = $arSTORES;
            $keyNoStore = array_search('NOT_REF', $arSelStores);
            if (false !== $keyNoStore) {
                unset($arSelStores[$keyNoStore]); // Остаются только коды выбранных складов
            }
        }


        if (strlen($strImportErrorMessage) <= 0) {
            $bIBlockIsCatalog = false;
            if (CCatalog::GetByID($IBLOCK_ID))
                $bIBlockIsCatalog = true;

            //Здесь начинаем загрузку xml файла

            if (file_exists($_SERVER["DOCUMENT_ROOT"].$DATA_FILE_NAME)) {
                $xml = $this->GetXMLObject($_SERVER["DOCUMENT_ROOT"] . $DATA_FILE_NAME);
            } else {
				$uf = file_get_contents($URL_DATA_FILE2);
				file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/upload/file_for_import.xml", $uf);
				$handle = fopen($_SERVER["DOCUMENT_ROOT"] . "/upload/file_for_import.xml", 'w+');
				fwrite($handle, $uf);
				fclose($handle);
				$DATA_FILE_NAME = "/upload/file_for_import.xml";
				$xml = $this->GetXMLObject($_SERVER["DOCUMENT_ROOT"] . $DATA_FILE_NAME);
			}

            if (!is_object($xml->shop)) {
                $strImportErrorMessage .= GetMessage("CICML_INVALID_FILE") . "<br>";
            }

        }

        if (strlen($strImportErrorMessage) <= 0) {
			set_time_limit(0);

            if ($this->isAllPropValsInOneProp) {
                $ONE_PROP_CODE = (empty($ONE_PROP_CODE)) ? 'CML2_ATTRIBUTES' : $ONE_PROP_CODE;
    			//Проверим существует ли свойство CML2_ATTRIBUTES и если не сущестует, создадим его
    			$properties = CIBlockProperty::GetList(
                    array(
                        "sort" => "asc",
                        "name" => "asc"
                    ),
                    array(
                        "ACTIVE" => "Y",
                        "IBLOCK_ID" => $IBLOCK_ID,
                        "CODE" => $ONE_PROP_CODE
                    )
                );
    			if (!$properties->GetNext()) {
    				$arNewPropFields = array(
    					"NAME"             => GetMessage("CML2_ATTRIBUTES"),
    					"ACTIVE"           => "Y",
    					"SORT"             => "100",
    					"CODE"             => $ONE_PROP_CODE,
    					"PROPERTY_TYPE"    => "S",
    					"MULTIPLE"         => 'Y',
    					"WITH_DESCRIPTION" => 'Y',
    					"IBLOCK_ID"        => $IBLOCK_ID,
    					'SMART_FILTER'     => 'Y', 
    				);
    								  
    				$Newibp = new CIBlockProperty;
    								
    				if (!$NewPropID = $Newibp->Add($arNewPropFields)) {
                        AddMessage2Log("Error create property $ONE_PROP_CODE");
                    }
    			}
            }

            $arPriceType = array();

            ///////////////////////////////////////////
            // Импорт категорий товаров из yml файла //
            ///////////////////////////////////////////

            if ($ONLY_PRICE != 'Y') {
                $ResCatArr = array(); //Сюда буду складывать найденные или добавленные айдишники для каждой категории

                $CategiriesList = $xml->shop->categories->category;

                foreach ($CategiriesList as $Categoria) {

                    $CATEGORIA_XML_ID = 'yml_' . $Categoria['id'];

    				if ($IMPORT_CATEGORY_SECTION == 'Y') {
    			
    					$CATEGORIA_PARENT_XML_ID = $Categoria['parentId'] ? 'yml_' . $Categoria['parentId'] : $ID_SECTION;
    			
    				} else {
    					$CATEGORIA_PARENT_XML_ID = $Categoria['parentId'] ? 'yml_' . $Categoria['parentId'] : 0;// Если родитель не указан - пусть категория идёт в корень
    				}

                    $CATEGORIA_NAME = yml_iconv($Categoria, $OPTION_ENCODING);

                    // Ищем, существует ли такая категория на сайте

                    $find_section_res = CIBlockSection::GetList(
                        array(),
                        array(
                            "IBLOCK_ID" => $IBLOCK_ID,
                            "XML_ID" => $CATEGORIA_XML_ID
                        ),
                        false,
                        array("ID"),
                        false
                    );
                    if ($find_section_res2 = $find_section_res->GetNext()) {
                        $ResCatArr[$CATEGORIA_XML_ID] = $find_section_res2["ID"];

    					if ($ResCatArr[$CATEGORIA_XML_ID] == 0 && $IMPORT_CATEGORY_SECTION == 'Y') {
    						$ResCatArr[$CATEGORIA_XML_ID] = $ID_SECTION;
    					}

                        $bs = new CIBlockSection;
                        $arFields = array(
                            "ACTIVE"            => "Y",
                            "IBLOCK_ID"         => $IBLOCK_ID,
                            "NAME"              => $CATEGORIA_NAME,
                            "IBLOCK_SECTION_ID" => $ResCatArr[$CATEGORIA_PARENT_XML_ID],
                            "XML_ID"            => $CATEGORIA_XML_ID,
                            //  "CODE"=>$section_code,
                        );

                        if ($IMPORT_CATEGORY == 'Y') {
                            $bs->Update($find_section_res2["ID"], $arFields);
                        }
                    } else { // Такой категории товаров не нашлось
                        //Добавляю
    					
    					if ($ResCatArr[$CATEGORIA_PARENT_XML_ID] == 0 && $IMPORT_CATEGORY_SECTION == 'Y') {
    						$ResCatArr[$CATEGORIA_PARENT_XML_ID] = $ID_SECTION;
    					}

                        $section_code = CUtil::translit($CATEGORIA_NAME, LANGUAGE_ID, array(
                            "max_len" => 50,
                            "change_case" => 'U', // 'L' - toLower, 'U' - toUpper, false - do not change
                            "replace_space" => '_',
                            "replace_other" => '_',
                            "delete_repeat_replace" => true,
                        ));
                        if (preg_match('/^[0-9]/', $section_code)) {
                            $section_code = '_' . $section_code;
                        }

                        $bs = new CIBlockSection;
                        $arFields = array(
                            "ACTIVE"            => "Y",
                            "IBLOCK_ID"         => $IBLOCK_ID,
                            "NAME"              => $CATEGORIA_NAME,
                            "IBLOCK_SECTION_ID" => $ResCatArr[$CATEGORIA_PARENT_XML_ID],
                            "XML_ID"            => $CATEGORIA_XML_ID,
                            "CODE"              => 'yml_' . $section_code,
                        );

                        if ($IMPORT_CATEGORY == 'Y') {
                            $ResCatArr[$CATEGORIA_XML_ID] = $bs->Add($arFields);

                            if(!$ResCatArr[$CATEGORIA_XML_ID]) {
                                AddMessage2Log("Error create category \"" . $CATEGORIA_NAME . "\"");
                                echo $bs->LAST_ERROR;
                            }
                        }
                    }
                } // Конец обработки всех категорий товаров
            }


            $ib = new CIBlock;
            $res = CIBlock::GetList(
                array(),
                array(
                    "=TYPE" => $IBLOCK_TYPE_ID,
                    "IBLOCK_ID" => $IBLOCK_ID,
                    'CHECK_PERMISSIONS' => 'Y',
                    'MIN_PERMISSION' => 'W'
                )
            );

            if (!$res) {
                $strImportErrorMessage .= str_replace("#ERROR#", $ib->LAST_ERROR, str_replace("#NAME#", "[" . $IBLOCK_ID . "] \"" . $IBLOCK_NAME . "\" (" . $IBLOCK_XML_ID . ")", GetMessage("CICML_ERROR_ADDING_CATALOG"))) . ".<br>";
                $STT_CATALOG_ERROR++;
            } else {
                // Начинаем обработку товаров
                // Сначала получим все существующие свойства инфоблока, полученные ранее из импорта YML
                $this->arYmlCatalogProps = $this->getCatalogProps($IBLOCK_ID, true);

				$el = new CIBlockElement();
                $arProducts = array();
                $products = $xml->shop->offers->offer;

                print GetMessage("CET_PROCESS_GOING");
				print ("<br>");
				print (GetMessage("IMPORT_MSG1") . $CUR_FILE_POS);
				print (GetMessage("IMPORT_MSG2") . count($products));
				print (GetMessage("IMPORT_MSG3"));

                $isNeedPriceChange = (round(doubleval($price_modifier), 2) !== 1.00) ? true: false;

                for ($j = $CUR_FILE_POS; $j < count($products); $j++) {
                    $isNeedPropUpdate = true; // Нужно ли обновлять значения свойств товара
                    // устанавливаем значение до которого добрались
                    $CUR_FILE_POS = $j;

                    $xProductNode = $products[$j];

                    $PRODUCT_XML_ID = 'yml_' . $xProductNode['id'];

                    $PRODUCT_TYPE = $xProductNode['type'];

                    // выцепляем тип товара и получаем его название
                    switch ($PRODUCT_TYPE) {
                        case "vendor.model":
                            $PRODUCT_NAME_UNCODED = $xProductNode->vendor . " " . $xProductNode->model;
                            break;

                        case "book":
                        case "audiobook":
                            $PRODUCT_NAME_UNCODED = $xProductNode->author . " " . $xProductNode->name;
                            break;

                        case "artist.title":
                            $PRODUCT_NAME_UNCODED = $xProductNode->artist . " " . $xProductNode->title;
                            break;

                        default:
                            $PRODUCT_NAME_UNCODED = $xProductNode->name;
                    }

					// $PRODUCT_NAME_UNCODED = $xProductNode->typePrefix." ".$xProductNode->model;
                    // $PRODUCT_NAME_UNCODED = $xProductNode->model;
					// if (!isset($PRODUCT_NAME_UNCODED)) $PRODUCT_NAME_UNCODED=$xProductNode->name;

					$PRODUCT_NAME = yml_iconv(trim($PRODUCT_NAME_UNCODED), $OPTION_ENCODING);
                    $prodDescription = yml_iconv((string)$xProductNode->description, $OPTION_ENCODING);

					$is_import_by_filter = false;
					$import_by_filter = array();
					if (!empty($CAT_FILTER_I)) {
						$import_by_filter = explode(',', $CAT_FILTER_I);
						$is_import_by_filter = true;
					}

					$is_filtreded = true;
					if ($is_import_by_filter) {
						$is_filtreded = false;
						foreach ($import_by_filter as $val) {
							if (strpos($PRODUCT_NAME, $val) !== false || strpos($prodDescription, $val) !== false) {
								$is_filtreded = true;
							}
						}
					}

                    $PRODUCT_XML_CAT_ID = 'yml_' . $xProductNode->categoryId;

                    $ProductPrice = $xProductNode->price;
					
					// price changing
					if ($isNeedPriceChange) {
						$ProductPrice = $ProductPrice * doubleval($price_modifier);
					}

                    // Обработаем свойства товара: создадим свойства инфоблока и/или значения свойств по данным этого товара
                    $prodParams = $xProductNode->param;
                    if ($ONLY_PRICE != 'Y' && !empty($prodParams) && !$this->isAllPropValsInOneProp) {
                        $arProdProps = $this->processProps($prodParams);
                        if (false === $arProdProps) {
                            AddMessage2Log("Не удалось обработать свойства товара: " . print_r($prodParams, true));
                        }
                    }
					
					if ($is_filtreded) {
					
						$yml_tags_array = array("vendor", "vendorCode", "country_of_origin", "sales_notes", "manufacturer_warranty", "barcode");

						$more_photo = array();
						$n = 0;
						$p = 0;
						
						$count_pik = 0;
						foreach ($xProductNode->picture as $dop_pic) {
							$count_pik++; // Первую картинку не фигачим в дополнительные картинки - она уже в детальную ушла
							if ($count_pik > 1) {
								$dop_pic_arr = CFile::MakeFileArray($dop_pic);
								$dop_pic_arr["MODULE_ID"] = "iblock";
								$more_photo['n' . $p] = $dop_pic_arr;
								$p++;
							}
						}

                        $arLoadProductArray = array(
                            "MODIFIED_BY"		=> $USER->GetID(),
                            "IBLOCK_SECTION"	=> $ResCatArr[$PRODUCT_XML_CAT_ID],
                            "IBLOCK_ID"			=> $IBLOCK_ID,
                            "NAME"				=> $PRODUCT_NAME,
                            "XML_ID"		    => $PRODUCT_XML_ID,
                            "ACTIVE"            => $xProductNode['available'] == 'true' ? 'Y' : 'N',
                            "DETAIL_PICTURE"    => CFile::MakeFileArray($xProductNode->picture[0]),
    						"PREVIEW_PICTURE"   => CFile::MakeFileArray($xProductNode->picture[0]),
                            "DETAIL_TEXT"       => $prodDescription,
                            "DETAIL_TEXT_TYPE"  => 'html',
                            // получаем код товара
                            // "CODE" => CUtil::translit(($vendor?$vendor:$PRODUCT_NAME)." ".$articul, 'ru', array()),
    						"CODE"              => $articul,
                        );

					    $arLoadProductArray2 = array(
                            "MODIFIED_BY"     => $USER->GetID(),
                            "IBLOCK_ID"       => $IBLOCK_ID,
                            "NAME"            => $PRODUCT_NAME,
                            "XML_ID"          => $PRODUCT_XML_ID,
                            "ACTIVE"          => $xProductNode['available']=='true'?'Y':'N',
                            "DETAIL_PICTURE"  => CFile::MakeFileArray($xProductNode->picture[0]),
    						"PREVIEW_PICTURE" => CFile::MakeFileArray($xProductNode->picture[0]),
                            "DETAIL_TEXT"     => $prodDescription,
    						//"IBLOCK_SECTION"	=>	$ResCatArr["".$PRODUCT_XML_CAT_ID.""],
                        );

                        $res = CIBlockElement::GetList(
                            array(),
                            array(
                                "IBLOCK_ID" => $IBLOCK_ID,
                                "XML_ID" => $PRODUCT_XML_ID
                            )
                        );
                        $bNewRecord_tmp = False;

                        // флажок что все ништяк
                        $flag_ok = 0;

                        $PRODUCT_ID = false;
    					// товар уже есть?
                        if ($arr = $res->Fetch()) { // Товар с таким XML_ID уже есть
                            $PRODUCT_ID = $arr["ID"];

                            if ($ONLY_PRICE != 'Y') {
                                // обновляем
                                $flag_ok = $el->Update($PRODUCT_ID, $arLoadProductArray2);
                                //fwrite($fp, "already was. updated ".$PRODUCT_XML_ID." ".$PRODUCT_NAME."\n");

                                // уже есть такой код
                                if (!$flag_ok) {
                                    // да жалко что ли. поменяем
                                    $arLoadProductArray["CODE"] = $arLoadProductArray["XML_ID"];
                                    // еще раз обновляй
                                    $flag_ok = $el->Update($PRODUCT_ID, $arLoadProductArray);
                                    //fwrite($fp, "code changed to xmlid ".$PRODUCT_XML_ID." ".$PRODUCT_NAME."\n");
                                }

    							$va_props = CIBlockElement::GetProperty($IBLOCK_ID, $PRODUCT_ID, array(), array("CODE" => "MORE_PHOTO"));
    							while ($pic_props = $va_props->Fetch()) {
    								if ($pic_props["VALUE"]) {
    									$ar_del[$pic_props["PROPERTY_VALUE_ID"]] = array("VALUE" => array("del" => "Y"));
    									CIBlockElement::SetPropertyValueCode($PRODUCT_ID, "MORE_PHOTO", $ar_del);
    									CFile::Delete($pic_props["VALUE"]);
    								}
    							}

    							CIBlockElement::SetPropertyValueCode($PRODUCT_ID, "MORE_PHOTO", $more_photo);
                            } else {
    						    $flag_ok = true;
    						}
                        } else { // Товара c таким XML_ID пока нет
                            if ($ONLY_PRICE != 'Y') {
                                // добавляем
    							$flag_ok = false;
                                // Дополним данные свойствами товара
                                if (isset($arProdProps) && !empty($arProdProps) && is_array($arProdProps)) {
                                    $arLoadProductArray['PROPERTY_VALUES'] = $arProdProps;
                                }
                                $PRODUCT_ID = $el->Add($arLoadProductArray);
    							if ($PRODUCT_ID) {
                                    $flag_ok = true;
                                    $isNeedPropUpdate = false;
                                }
                                //fwrite($fp, "new record ".$PRODUCT_XML_ID." ".$PRODUCT_NAME."\n");

                                // не добавился! такой код уже есть
                                if (!$flag_ok) {
                                    // поменяли
                                    $arLoadProductArray["CODE"] = $arLoadProductArray["XML_ID"];
                                    // еще раз добавляй
                                    $PRODUCT_ID = $el->Add($arLoadProductArray);
    								if ($PRODUCT_ID) {
                                        $flag_ok = true;
                                        $isNeedPropUpdate = false;
                                    }
                                    //  fwrite($fp, "code changed to xmlid ".$PRODUCT_XML_ID." ".$PRODUCT_NAME."\n");
                                }

							    $va_props = CIBlockElement::GetProperty($IBLOCK_ID, $PRODUCT_ID, array(), array("CODE" => "MORE_PHOTO"));
								while ($pic_props = $va_props->Fetch()) {
									if ($pic_props["VALUE"]) {
										$ar_del[$pic_props["PROPERTY_VALUE_ID"]] = array("VALUE" => array("del" => "Y"));
										CIBlockElement::SetPropertyValueCode($PRODUCT_ID, "MORE_PHOTO", $ar_del);
										CFile::Delete($pic_props["VALUE"]);
									}
								}

							    CIBlockElement::SetPropertyValueCode($PRODUCT_ID, "MORE_PHOTO", $more_photo);

                            } else { // Режим обновления только цен
    							$flag_ok = true;
    						}
                        } // Закончили обработку товара с новым XML_ID

                        if ($flag_ok) {
                            $prodQuantity = (int) $xProductNode->catalogQuantity;
                            if ($ONLY_PRICE != 'Y') {
                                $arFieldsProduct = array(
                                    "ID" => $PRODUCT_ID,
                                    "QUANTITY" => $prodQuantity,
                                    "CAN_BUY_ZERO" => "Y"
                                );
                                CCatalogProduct::Add($arFieldsProduct);
                            } else if ($ONLY_PRICE == 'Y') {
                                CCatalogProduct::Update($PRODUCT_ID, array('QUANTITY' => $prodQuantity));
                            }

                            // Остатки по складам
                            if ($this->isNeedStores) {
                                foreach ($arSelStores as $storeId) {
        						    $arFieldsSklad = array(
        								"PRODUCT_ID" => $PRODUCT_ID,
        								"STORE_ID" => $storeId,
        								"AMOUNT" => $prodQuantity,
        							);
        						    if ($ONLY_PRICE != 'Y') {
                                        CCatalogStoreProduct::Add($arFieldsSklad);
                                    } else if ($ONLY_PRICE == 'Y') {
                                        $rsStore = CCatalogStoreProduct::GetList(
                                            array(),
                                            array(
                                                'PRODUCT_ID' => $PRODUCT_ID,
                                                'STORE_ID' => $storeId
                                            ),
                                            false,
                                            false,
                                            array('ID', 'AMOUNT')
                                        ); 
                                        if ($arStore = $rsStore->Fetch()) {
                                            $storeRowId = $arStore['ID'];
                                            if ($arStore['AMOUNT'] != $prodQuantity) {
                                                CCatalogStoreProduct::Update($storeRowId, $arFieldsSklad);
                                            }
                                        } else {
                                            // По этому складу нет данных об остатках этого товара
                                            CCatalogStoreProduct::Add($arFieldsSklad);
                                        }
                                    }
                                }
                            }

                            //Обновляем базовую цену для товара
                            $price_ok = CPrice::SetBasePrice($PRODUCT_ID, $ProductPrice, "RUB");

                            ///////////////////////////////
                            // Обновление свойств товара //
                            ///////////////////////////////

                            if ($ONLY_PRICE != 'Y') {
                                // После того, как основная информация по товару записана, сохраняем свойства
                                if ($this->isAllPropValsInOneProp) {
                                    $PROPERTY_VALUE = array();
                                    $count = 0;

        							if (isset($prodParams)) {
        								foreach ($prodParams as $param) {
        	                                // print $param['name']."<br>";
        									$PROPERTY_VALUE['n' . $count] = array(
                                                'VALUE' => yml_iconv($param, $OPTION_ENCODING),
                                                'DESCRIPTION' => yml_iconv($param['name'], $OPTION_ENCODING)
                                            );
        									$count++;
        								}
        							}
    							
        							foreach ($yml_tags_array as $val) {
        								$PROPERTY_VALUE['n' . $count] = array(
                                            'VALUE' => yml_iconv($xProductNode->$val, $OPTION_ENCODING),
                                            'DESCRIPTION' => $val
                                        );
        								$count++;
        							}

                                    $ELEMENT_ID = $PRODUCT_ID;  // код элемента

                                    CIBlockElement::SetPropertyValuesEx(
                                        $ELEMENT_ID,
                                        $IBLOCK_ID,
                                        array($ONE_PROP_CODE => $PROPERTY_VALUE)
                                    );
                                } else {
                                    if ($isNeedPropUpdate) {
                                        // Сохраняем свойства товара в соответствующих свойствах инфоблока
                                        foreach ($prodParams as $param) {
                                            $paramName = (string) $param['name'];
                                            $PROPERTY_CODE = $this->ymlPropPrefix . $this->getTranslit(trim($paramName), 'U');
                                            $PROPERTY_VALUE = yml_iconv((string) $param, $OPTION_ENCODING);
                                            $PROPERTY_VALUE = trim($PROPERTY_VALUE);
                                            CIBlockElement::SetPropertyValuesEx(
                                                $PRODUCT_ID,
                                                $IBLOCK_ID,
                                                array($PROPERTY_CODE => $PROPERTY_VALUE)
                                            );
                                        }
                                    }
                                }
                            } // Конец очередной проверки, что это не режим обновления только цен
                        } else { // Если флаг $flag_ok == false
                            echo "\nError: " . $el->LAST_ERROR . "\n";
                            echo $PRODUCT_XML_ID . " " . $PRODUCT_NAME . "\n\n";
                           // fwrite($fp, "here was error ".$PRODUCT_XML_ID." ".$PRODUCT_NAME."\n");
                        }
    				} // Конец блока, где флаг $is_filtreded == true

                    // fclose($fp);

                    // если таймер закончился, $bAllLinesLoaded = false
                    if (!($bAllLinesLoaded = $this->CSVCheckTimeout($max_execution_time))) {
                        break;
    				}
                } // Закончился цикл по товарам
            } // Конец проверки, что инфоблок доступен для редактирования
        } // Конец начальной проверки на наличие ошибок

        // не успели закончить до таймера
        if (!$bAllLinesLoaded) {
            // увеличиваем позицию
            $CUR_FILE_POS++;
            $this->FILE_POS = $CUR_FILE_POS;
            // флажок что надо перезагрузиться
            $this->AllLinesLoaded = false;

        }

        if ($bTmpUserCreated) {
            unset($USER);
            if (isset($USER_TMP)) {
                $USER = $USER_TMP;
            }
            unset($USER_TMP);
        }

        return $strImportErrorMessage;
    } // Конец функции импорта


    /**
     * Метод возвращает массив либо всех свойств заданного каталога, либо только свойств,
     * полученных ранее при импорте YML (свойства, в кодах которых префикс 'YML_')
     * 
     * @param string $IBLOCK_ID Идентификатор инфоблока
     * @param boolean $isYmlOnly Только свойства из YML импорта
     * 
     * @return array Массив свойств инфоблока и их значчений
     */
    public function getCatalogProps($IBLOCK_ID, $isYmlOnly = false)
    {
        $ibId = (int) $IBLOCK_ID;
        $arResult = array();
        if (!$ibId) return $arResult;

        $arFilter = array(
            "ACTIVE" => "Y",
            "IBLOCK_ID" => $ibId
        );
        if ($isYmlOnly) {
            $arFilter = array_merge($arFilter, array('CODE' => $this->ymlPropPrefix . '%'));
        }
        $properties = CIBlockProperty::GetList(
            array("sort" => "asc", "name" => "asc"),
            $arFilter
        );
        while ($prop_fields = $properties->Fetch()) {
            $arResult[$prop_fields['ID']] = array(
                'ID'            => $prop_fields['ID'],
                'CODE'          => $prop_fields['CODE'],
                'NAME'          => $prop_fields['NAME'],
                'PROPERTY_TYPE' => $prop_fields['PROPERTY_TYPE']
            );
            if ($prop_fields['PROPERTY_TYPE'] == 'L') {
                // Получим список всех значений
                $property_enums = CIBlockPropertyEnum::GetList(
                    array("NAME" => "ASC", "SORT" => "ASC"),
                    array("IBLOCK_ID" => $ibId, "CODE" => $prop_fields['CODE'])
                );
                while($enum_fields = $property_enums->GetNext()) {
                    $arResult[$prop_fields['ID']]['VALUES'][$enum_fields['ID']] = array(
                        'ID'     => $enum_fields['ID'],
                        'XML_ID' => $enum_fields['XML_ID'],
                        'VALUE'  => $enum_fields['VALUE']
                    );
                }
            }
        }
        //AddMessage2Log('$arResult = ' . print_r($arResult, true));

        return $arResult;
    }


    /**
     * Метод создаёт свойства инфоблока и/или значения списочных свойств
     * 
     * @param object $prodParams Массив свойств товара из YML файла
     * 
     * @return mexed Массив свойств для вставки в товар, либо ЛОЖЬ в случае ошибки
     */
    public function processProps($prodParams)
    {
        if (!empty($prodParams)) {
            $arProdProps = array();
            foreach ($prodParams as $param) {
                $paramName = (string) $param['name'];
                $paramValue = yml_iconv((string) $param, $OPTION_ENCODING);
                $paramCode = $this->ymlPropPrefix . $this->getTranslit($paramName, 'U');
                if (filter_var($paramValue, FILTER_VALIDATE_FLOAT) !== false ||
                    filter_var($paramValue, FILTER_VALIDATE_INT) !== false) {
                    // Это числовое значение
                    $isNumeric = true;
                } else {
                    // Это строковое значение
                    $isNumeric = false;
                }
                if ($propId = array_search($paramCode, array_column($this->arYmlCatalogProps, 'CODE', 'ID'))) {
                    if (!$isNumeric) {
                        // Проверим, есть ли текущее значение среди списка значений
                        $arPropValues = $this->arYmlCatalogProps[$propId]['VALUES'];
                        $propValXmlId = $this->getTranslit($paramValue);
                        $propValId = array_search($propValXmlId, array_column($arPropValues, 'XML_ID', 'ID'));
                        if (false === $propValId) {
                            // Такого значения в списке нет - добавляем
                            $ibpenum = new CIBlockPropertyEnum;
                            $arFields = array(
                                'PROPERTY_ID' => $propId,
                                'VALUE'       => $paramValue,
                                'XML_ID'      => $propValXmlId
                            );
                            if ($propValId = $ibpenum->Add($arFields)) {
                                // Добавляем значение свойства во внутренний список этого класса - для последующих проверок
                                $this->arYmlCatalogProps[$propId]['VALUES'][$propValId] = array(
                                    'ID'     => $propValId,
                                    'XML_ID' => $propValXmlId,
                                    'VALUE'  => $paramValue
                                );
                            } else {
                                AddMessage2Log("Не удалось добавить значение $paramValue в список свойства $paramCode");
                                return false;
                            }
                        }
                    }
                } else {
                    // Добавляем свойство в инфоблок
                    $ibp = new CIBlockProperty;
                    $arFields = array(
                        'NAME'      => $paramName,
                        'ACTIVE'    => 'Y',
                        'CODE'      => $paramCode,
                        'IBLOCK_ID' => $this->iblockId,
                        'SEARCHABLE' => 'Y',
                        'FILTRABLE' => 'Y'
                    );
                    if ($isNumeric) {
                        $arFields = array_merge($arFields, array(
                            'PROPERTY_TYPE' => 'N'
                        ));
                    } else {
                        $propValXmlId = $this->getTranslit($paramValue);
                        $arFields = array_merge($arFields, array(
                            'PROPERTY_TYPE' => 'L',
                            'VALUES'        => array(
                                array(
                                    'VALUE'  => $paramValue,
                                    'XML_ID' => $propValXmlId
                                )
                            )
                        ));
                    }
                    if ($propId = $ibp->Add($arFields)) {
                        // Добавляем свойство во внутренний список этого класса - для последующих проверок
                        $this->arYmlCatalogProps[$propId] = array(
                            'ID'   => $propId,
                            'CODE' => $paramCode,
                            'NAME' => $paramName
                        );
                        if ($isNumeric) {
                            $this->arYmlCatalogProps[$propId]['PROPERTY_TYPE'] = 'N';
                        } else {
                            $this->arYmlCatalogProps[$propId]['PROPERTY_TYPE'] = 'L';
                            // Получим идентификатор добавленного значения списочного свойства
                            $db_enum_list = CIBlockProperty::GetPropertyEnum(
                                $propId,
                                array(),
                                array(
                                    "IBLOCK_ID"   => $this->iblockId,
                                    "EXTERNAL_ID" => $propValXmlId
                                )
                            );
                            if ($ar_enum_list = $db_enum_list->GetNext()) {
                                $propValId = $ar_enum_list["ID"];
                            }
                            $this->arYmlCatalogProps[$propId]['VALUES'][$propValId] = array(
                                'ID'     => $propValId,
                                'XML_ID' => $propValXmlId,
                                'VALUE'  => $paramValue
                            );
                        }
                    } else {
                        AddMessage2Log("Не удалось создать свойство $paramCode: " . print_r($ibp->LAST_ERROR, true));
                        return false;
                    }
                }
                // На данном этапе свойство существует в инфоблоке
                if ($isNumeric) {
                    if (isset($paramValue) && !is_null($paramValue)) {
                        $arProdProps[$propId] = $paramValue;
                    }
                } else {
                    if (!empty($propValId)) {
                        $arProdProps[$propId] = $propValId;
                    }
                }
            }

            return $arProdProps;
        } else {
            return false;
        }
    }


    /**
     * Метод возвращает транслитерированную строку
     *
     * @param string $name Строка, по которой требуется получить транслитерацию
     * @param mixed $case Вариант преобразования регистра. Допустимы значения:
     *     'L' - к нижнему регистру
     *     'U' - к верхнему регистру
     *     false - не изменять
     *
     * @return string Результат обработки строки
     */
    public function getTranslit($name, $case = 'L')
    {
        $name = trim($name);
        $case = trim($case);
        if ($case == 'L' || $case == 'U' || $case === false) {
            
        } else {
            $case = 'L';
        }
        $result = '';

        if (!empty($name)) {
            $result = Cutil::translit($name, "ru", array('change_case' => $case));
        }

        return $result;
    }


    /**
     * Метод возвращает массив, пригодный для генерации выпадающего списка функцией SelectBoxMFromArray
     *
     * @return array Массив с данными о складах для применения в функции SelectBoxMFromArray
     */
    public static function getStoresForSelect()
    {
        $arResult = array();
        $resStores = CCatalogStore::GetList(
            array('TITLE' => 'ASС'),
            array('ACTIVE' => 'Y'),
            false,
            false,
            array('ID', 'TITLE')
        );
        while ($arStore = $resStores->Fetch()) {
            $arResult['REFERENCE'][] = $arStore['TITLE'];
            $arResult['REFERENCE_ID'][] = $arStore['ID'];
        }

        return $arResult;
    }

} // End of Class
