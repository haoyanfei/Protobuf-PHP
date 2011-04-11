<?php

namespace DrSlump\Protobuf\Compiler;

use DrSlump\Protobuf;
use google\protobuf as proto;

class JsonGenerator extends AbstractGenerator
{
    public function getNamespace(proto\FileDescriptorProto $proto)
    {
        $namespace = $proto->getPackage();
        $opts = $proto->getOptions();
        if (isset($opts['json.package'])) {
            $namespace = $opts['jsonpackage'];
        }
        if (isset($opts['json.namespace'])) {
            $namespace = $opts['json.namespace'];
        }

        $namespace = trim($namespace, '.');
        return $namespace;
    }

    public function compileEnum(proto\EnumDescriptorProto $enum, $namespace)
    {
        $s[]= "$namespace.$enum->name = {";
        $lines = array();
        foreach ($enum->getValueList() as $value) {
            $lines[] = "  /** @const */ $value->name: $value->number";
        }
        $s[]= implode(",\n", $lines);
        $s[]= '};';
        $s[]= '';
        return implode("\n", $s);
    }

    public function compileExtension(proto\FieldDescriptorProto $field, $ns, $indent)
    {
        $extendee = $this->normalizeReference($field->getExtendee());
        $name = $field->getName();
        if ($ns) {
            $name = $ns . '.' . $name;
        }
        $field->setName($name);

        $s[]= "ProtoJson.extend($extendee, {";
        $s[]= "  $field->number: " . $this->generateField($field);
        $s[]= "});";
        $s[]= '';

        return $indent . implode("\n$indent", $s);
    }

    public function compileMessage(proto\DescriptorProto $msg, $namespace)
    {
        $s[]= "/**";
        $s[]= " * @constructor";
        $s[]= " * @augments {ProtoJson.Message}";
        $s[]= " * @extends ProtoJson.Message";
        $s[]= " * @memberOf $namespace";
        $s[]= " * @param {object} data - Optional, provide initial data to parse";
        $s[]= " */";
        $s[]= "$namespace.$msg->name = ProtoJson.create({";
        $s[]= "  fields: {";

        $lines = array();
        foreach ($msg->getFieldList() as $field) {
            $lines[] = "    $field->number: " . $this->generateField($field);
        }
        $s[] = implode(",\n", $lines);

        $s[]= "  },";
        $s[]= "  ranges: [";
        // @todo dump extension ranges
        $s[]= "  ]";
        $s[]= "});";
        $s[]= "";

        // Compute a new namespace with the message name as suffix
        $namespace .= "." . $msg->getName();

        // Generate getters/setters
        foreach ($msg->getFieldList() as $field) {
            $s[]= $this->generateAccessors($field, $namespace);
        }

        // Generate Enums
        foreach ($msg->getEnumTypeList() as $enum):
        $s[]= $this->compileEnum($enum, $namespace);
        endforeach;

        // Generate nested messages
        foreach ($msg->getNestedTypeList() as $msg):
        $s[]= $this->compileMessage($msg, $namespace);
        endforeach;

        // Collect extensions
        foreach ($msg->getExtensionList() as $field) {
            $this->extensions[$field->getExtendee()][] = array($namespace, $field);
        }

        return implode("\n", $s);
    }

    public function compileProtoFile(proto\FileDescriptorProto $proto)
    {
        $file = new proto\compiler\CodeGeneratorResponse\File();

        $opts = $proto->getOptions();
        $name = pathinfo($proto->getName(), PATHINFO_FILENAME);
        $name .= isset($opts['json.suffix'])
                 ? $opts['json.suffix']
                 : '.js';
        $file->setName($name);

        $namespace = $this->getNamespace($proto);

        $s[]= "// DO NOT EDIT! Generated by Protobuf for PHP protoc plugin " . Protobuf::VERSION;
        $s[]= "// Source: " . $proto->getName();
        $s[]= "//   Date: " . date('Y-m-d H:i:s');
        $s[]= "";

        $s[]= "(function(){";
        $s[]= "/** @namespace */";
        $s[]= "var $namespace = $namespace || {};";
        $s[]= "";
        $s[]= "// Make it CommonJS compatible";
        $s[]= "if (typeof exports !== 'undefined') {";
        $s[]= "  var ProtoJson = this.ProtoJson;";
        $s[]= "  if (!ProtoJson && typeof require !== 'undefined')";
        $s[]= "    ProtoJson = require('ProtoJson');";
        $s[]= "  $namespace = exports;";
        $s[]= "} else {";
        $s[]= "  this.$namespace = $namespace;";
        $s[]= "}";
        $s[]= "";


        // Generate Enums
        foreach ($proto->getEnumTypeList() as $enum) {
            $s[]= $this->compileEnum($enum, $namespace);
        }

        // Generate Messages
        foreach ($proto->getMessageTypeList() as $msg) {
            $s[] = $this->compileMessage($msg, $namespace);
        }

        // Collect extensions
        if ($proto->hasExtension()) {
            foreach ($proto->getExtensionList() as $field) {
                $this->extensions[$field->getExtendee()][] = array($namespace, $field);
            }
        }

        // Dump all extensions found in this proto file
        if (count($this->extensions)) {
            foreach ($this->extensions as $extendee => $fields) {
                foreach ($fields as $pair) {
                    list($ns, $field) = $pair;
                    $s[]= $this->compileExtension($field, $ns, '');
                }
            }
        }

        $s[]= "})();";

        $src = implode("\n", $s);
        $file->setContent($src);
        return array($file);
    }

    public function generateField(proto\FieldDescriptorProto $field)
    {
        $reference = 'null';
        if ($field->hasTypeName()) {
            $reference = $field->getTypeName();
            if (substr($reference, 0, 1) !== '.') {
                throw new \RuntimeException('Only fully qualified names are supported: ' . $reference);
            }
            $reference = "'" . $this->normalizeReference($reference) . "'";
        }

        $default = 'null';
        if ($field->hasDefaultValue()):
            switch ($field->getType()) {
            case Protobuf::TYPE_BOOL:
                $default = $field->getDefaultValue() ? 'true' : 'false';
                break;
            case Protobuf::TYPE_STRING:
                $default = '"' . addcslashes($field->getDefaultValue(), '"\\') . '"';
                break;
            case Protobuf::TYPE_ENUM:
                $default = $this->normalizeReference($field->getTypeName()) . '.' . $field->getDefaultValue();
                break;
            default: // Numbers
                $default = $field->getDefaultValue();
            }
        endif;

        $data = array(
            "'" . $field->getName() . "'",
            $field->getLabel(),
            $field->getType(),
            $reference,
            $default,
            '{}'
        );

        return '[' . implode(', ', $data) . ']';
    }

    public function generateAccessors($field, $namespace)
    {
        $camel = $this->comp->camelize(ucfirst($field->getName()));

        $s[]= "/**";
        $s[]= " * Check <$field->name> value";
        $s[]= " * @return {Boolean}";
        $s[]= " */";
        $s[]= "$namespace.prototype.has$camel = function(){";
        $s[]= "  return this._has($field->number);";
        $s[]= "};";
        $s[]= "";

        $s[]= "/**";
        $s[]= " * Set a value for <$field->name>";
        $s[]= " * @param {" . $this->getJsDoc($field) . "} value";
        $s[]= " * @return {".  $namespace . "}";
        $s[]= " */";
        $s[]= "$namespace.prototype.set$camel = function(value){";
        $s[]= "  return this._set($field->number, value);";
        $s[]= "};";
        $s[]= "";


        $s[]= "/**";
        $s[]= " * Clear the value of <$field->name>";
        $s[]= " * @return {".  $namespace . "}";
        $s[]= " */";
        $s[]= "$namespace.prototype.clear$camel = function(){";
        $s[]= "  return this._clear($field->number);";
        $s[]= "};";
        $s[]= "";


        if ($field->getLabel() !== Protobuf::RULE_REPEATED):

        $s[]= "/**";
        $s[]= " * Get <$field->name> value";
        $s[]= " * @return {" . $this->getJsDoc($field) . "}";
        $s[]= " */";
        $s[]= "$namespace.prototype.get$camel = function(){";
        $s[]= "  return this._get($field->number);";
        $s[]= "};";
        $s[]= "";

        else:

        $s[]= "/**";
        $s[]= " * Get an item from <$field->name>";
        $s[]= " * @param {int} idx";
        $s[]= " * @return {" . $this->getJsDoc($field) . "}";
        $s[]= " */";
        $s[]= "$namespace.prototype.get$camel = function(idx){";
        $s[]= "  return this._get($field->number, idx);";
        $s[]= "};";
        $s[]= "";


        $s[]= "/**";
        $s[]= " * Get <$field->name> value";
        $s[]= " * @return {" . $this->getJsDoc($field) . "[]}";
        $s[]= " */";
        $s[]= "$namespace.prototype.get{$camel}List = function(){";
        $s[]= "  return this._get($field->number);";
        $s[]= "};";
        $s[]= "";

        $s[]= "/**";
        $s[]= " * Add a value to <$field->name>";
        $s[]= " * @param {" . $this->getJsDoc($field) . "} value";
        $s[]= " * @return {" . $namespace . "}";
        $s[]= " */";
        $s[]= "$namespace.prototype.add$camel = function(value){";
        $s[]= "  return this._add($field->number, value);";
        $s[]= "};";
        $s[]= "";

        endif;


        return implode("\n", $s);
    }

    public function getJsDoc(proto\FieldDescriptorProto $field)
    {
        switch ($field->getType()) {
            case Protobuf::TYPE_DOUBLE:
            case Protobuf::TYPE_FLOAT:
                return 'Float';
            case Protobuf::TYPE_INT64:
            case Protobuf::TYPE_UINT64:
            case Protobuf::TYPE_INT32:
            case Protobuf::TYPE_FIXED64:
            case Protobuf::TYPE_FIXED32:
            case Protobuf::TYPE_UINT32:
            case Protobuf::TYPE_SFIXED32:
            case Protobuf::TYPE_SFIXED64:
            case Protobuf::TYPE_SINT32:
            case Protobuf::TYPE_SINT64:
                return 'Int';
            case Protobuf::TYPE_BOOL:
                return 'Boolean';
            case Protobuf::TYPE_STRING:
                return 'String';
            case Protobuf::TYPE_MESSAGE:
                return $this->normalizeReference($field->getTypeName());
            case Protobuf::TYPE_BYTES:
                return 'String';
            case Protobuf::TYPE_ENUM:
                return 'Int (' . $this->normalizeReference($field->getTypeName()) . ')';

            case Protobuf::TYPE_GROUP:
            default:
                return 'unknown';
        }
    }

    public function normalizeReference($reference)
    {
          // Remove leading dot
          $reference = ltrim($reference, '.');

          if (!$this->comp->hasPackage($reference)) {
              $found = false;
              foreach ($this->comp->getPackages() as $package=>$namespace) {
                  if (0 === strpos($reference, $package.'.')) {
                      $reference = $namespace . substr($reference, strlen($package));
                      $found = true;
                  }
              }
              if (!$found) {
                  $this->comp->warning('Non tracked package name found "' . $reference . '"');
              }
          } else {
              $reference = $this->comp->getPackage($reference);
          }

          return $reference;
    }
}